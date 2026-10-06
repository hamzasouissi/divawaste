<?php

namespace App\Modules\Reporting\Support;

use App\Modules\Common\Exceptions\BusinessRuleViolation;
use App\Modules\Common\Models\StoredFile;
use App\Modules\Common\Support\DocumentLanguage;
use App\Modules\Documents\Support\WasteRegister;
use App\Modules\Tenancy\Models\Company;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Builds the exportable documents (M5-01 register, M6-04 RSE report, M4-05 economic balance) as PDF or Excel,
 * with the client's logo and identity. Called from the queued job, inside the company's tenant context.
 */
final class ReportBuilder
{
    public const TYPES = ['waste_register', 'rse_summary', 'economic_balance'];

    public const FORMATS = ['pdf', 'xlsx'];

    /** A PDF beyond this size is unreadable and exhausts memory: the Excel export is the tool for large extracts. */
    public const MAX_PDF_ROWS = 3000;

    public const MAX_XLSX_ROWS = 200000;

    public function __construct(private readonly WasteRegister $register, private readonly WasteStatistics $statistics) {}

    /**
     * @param  array{from: string, to: string, site_ids?: ?list<int>, lang?: ?string}  $parameters
     * @return array{content: string, filename: string, mime: string}
     */
    public function build(string $type, string $format, array $parameters, Company $company): array
    {
        // In the language asked for when the report was requested; a queue worker has no language of its own.
        return DocumentLanguage::during(DocumentLanguage::supported($parameters['lang'] ?? $company->default_locale), fn () => $this->render($type, $format, $parameters, $company));
    }

    /**
     * @param  array{from: string, to: string, site_ids?: ?list<int>, lang?: ?string}  $parameters
     * @return array{content: string, filename: string, mime: string}
     */
    private function render(string $type, string $format, array $parameters, Company $company): array
    {
        $from = Carbon::parse($parameters['from'])->startOfDay();
        $to = Carbon::parse($parameters['to'])->startOfDay();
        $siteIds = $parameters['site_ids'] ?? null;
        $title = __("exports.report.{$type}");
        $filename = sprintf('%s_%s_%s.%s', str_replace('_', '-', $type), $from->format('Ymd'), $to->format('Ymd'), $format);
        $context = ['company' => $company, 'logo' => $this->logo($company), 'title' => $title, 'from' => $from, 'to' => $to, 'generatedAt' => now()->timezone($company->timezone)];

        if ($type === 'waste_register') {
            $query = $this->register->query($from, $to, $siteIds);
            $count = (clone $query)->count();
            $limit = $format === 'pdf' ? self::MAX_PDF_ROWS : self::MAX_XLSX_ROWS;
            if ($count > $limit) {
                throw new BusinessRuleViolation('REPORT_TOO_LARGE', "This period holds {$count} lots, above the {$limit} allowed for a {$format} export. Shorten the period".($format === 'pdf' ? ' or use Excel.' : '.'));
            }
            $rows = $query->get()->map(fn (object $row) => WasteRegister::exportLine($row, $company->timezone))->all();

            return $format === 'xlsx'
                ? $this->xlsx($filename, $title, WasteRegister::headings(), $rows)
                : $this->pdf($filename, 'pdf.register', $context + ['headings' => WasteRegister::headings(), 'rows' => $rows]);
        }

        $data = $this->statistics->dashboard($from, $to, $siteIds);

        if ($format === 'pdf') {
            return $this->pdf($filename, 'pdf.rse-report', $context + ['data' => $data, 'economicOnly' => $type === 'economic_balance'], 'portrait');
        }

        return $type === 'economic_balance'
            ? $this->xlsx($filename, $title, [__('documents.report.month'), __('documents.report.xlsx.evacuated_kg'), __('documents.report.xlsx.net_balance', ['currency' => $company->currency_code])],
                array_map(fn (array $m) => [$m['month'], (float) $m['evacuated_kg'], (float) $m['net_balance']], $data['by_month']))
            : $this->xlsx($filename, $title, [__('documents.report.xlsx.rubric'), __('documents.report.xlsx.label'), __('documents.report.xlsx.quantity_kg'), __('documents.report.lots')], [
                ...array_map(fn (array $r) => [__('documents.report.xlsx.waste_type'), $r['name'], (float) $r['kg'], $r['lots']], $data['by_waste_type']),
                ...array_map(fn (array $r) => [__('documents.report.site'), $r['name'], (float) $r['kg'], $r['lots']], $data['by_site']),
                ...array_map(fn (array $r) => [__('documents.report.channel'), $r['channel'] === null ? __('documents.report.xlsx.channel_undeclared') : __('exports.channel.'.$r['channel']), (float) $r['kg'], null], $data['by_channel']),
                ...array_map(fn (array $r) => [__('documents.report.xlsx.month_generated'), $r['month'], (float) $r['generated_kg'], null], $data['by_month']),
                [__('documents.report.xlsx.indicator'), __('documents.report.xlsx.recovery_rate'), $data['totals']['recovery_rate_pct'] === null ? null : (float) $data['totals']['recovery_rate_pct'], null],
                [__('documents.report.xlsx.indicator'), __('documents.report.xlsx.landfill_share'), $data['totals']['landfill_share_pct'] === null ? null : (float) $data['totals']['landfill_share_pct'], null],
                [__('documents.report.xlsx.indicator'), __('documents.report.xlsx.kg_per_piece'), $data['totals']['kg_per_piece'] === null ? null : (float) $data['totals']['kg_per_piece'], null],
            ]);
    }

    /**
     * @param  list<string>  $headings
     * @param  list<list<string|int|float|null>>  $rows
     * @return array{content: string, filename: string, mime: string}
     */
    private function xlsx(string $filename, string $title, array $headings, array $rows): array
    {
        return [
            'content' => Excel::raw(new ArrayExport($title, $headings, $rows), ExcelFormat::XLSX),
            'filename' => $filename,
            'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{content: string, filename: string, mime: string}
     */
    private function pdf(string $filename, string $view, array $data, string $orientation = 'landscape'): array
    {
        return ['content' => Pdf::loadView($view, $data)->setPaper('a4', $orientation)->output(), 'filename' => $filename, 'mime' => 'application/pdf'];
    }

    /**
     * M6-04: the client's logo, embedded so the document is self-contained.
     */
    private function logo(Company $company): ?string
    {
        $file = $company->logo_stored_file_id === null ? null : StoredFile::query()->where('company_id', $company->id)->find($company->logo_stored_file_id);

        return $file === null || ! Storage::disk($file->disk)->exists($file->path) ? null
            : 'data:'.$file->mime_type.';base64,'.base64_encode((string) Storage::disk($file->disk)->get($file->path));
    }
}
