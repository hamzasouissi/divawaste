<?php

namespace App\Modules\Documents\Actions;

use App\Modules\Common\Exceptions\BusinessRuleViolation;
use App\Modules\Common\Support\FileStorage;
use App\Modules\Documents\Enums\AnnualDeclarationStatus as Status;
use App\Modules\Documents\Enums\DocumentStatus;
use App\Modules\Documents\Enums\DocumentType;
use App\Modules\Documents\Models\AnnualDeclaration;
use App\Modules\Documents\Models\Document;
use App\Modules\Identity\Models\User;
use App\Modules\Sites\Models\Site;
use App\Modules\Tenancy\Models\Company;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * M5-03: annual waste declaration of a site. The figures are computed from the year's lots and pickups — produced,
 * evacuated per destination and channel, still on site at year end — never typed in. The format follows the
 * company's country (Tunisia: ANGed); other countries are added as formats, not as code paths (V2).
 */
final class DeclarationWorkflow
{
    /** Country code → declaration format. */
    public const FORMATS = ['TN' => 'TN_ANGED'];

    public function __construct(private readonly FileStorage $files, private readonly IssueDocument $issue) {}

    /**
     * Creates the draft of the year, or recomputes it if it already exists and is not validated yet.
     */
    public function prepare(Site $site, int $year, User $actor): AnnualDeclaration
    {
        $company = Company::query()->findOrFail($site->company_id);
        $format = self::FORMATS[(string) DB::table('countries')->where('id', $company->country_id)->value('iso2')] ?? null;
        if ($format === null) {
            throw new BusinessRuleViolation('DECLARATION_FORMAT_UNAVAILABLE', 'No declaration format is configured for this country yet.');
        }

        return DB::transaction(function () use ($site, $year, $format, $company) {
            $declaration = AnnualDeclaration::query()->where('site_id', $site->id)->where('format_code', $format)->where('reporting_year', $year)->lockForUpdate()->first();

            if ($declaration !== null && ! in_array($declaration->status, [Status::Draft, Status::InReview], true)) {
                throw new BusinessRuleViolation('DECLARATION_LOCKED', 'This declaration is validated and can no longer be recomputed.');
            }

            $declaration ??= new AnnualDeclaration(['site_id' => $site->id, 'format_code' => $format, 'reporting_year' => $year, 'status' => Status::Draft]);
            $declaration->forceFill(['data' => $this->compute($site, $year, $company), 'computed_at' => now()])->save();

            return $declaration;
        });
    }

    /**
     * Validation freezes the figures and archives the PDF for ten years.
     */
    public function validate(AnnualDeclaration $declaration, User $actor): AnnualDeclaration
    {
        return DB::transaction(function () use ($declaration, $actor) {
            $declaration = AnnualDeclaration::query()->whereKey($declaration->id)->lockForUpdate()->firstOrFail();
            if (! in_array($declaration->status, [Status::Draft, Status::InReview], true)) {
                throw new BusinessRuleViolation('DECLARATION_LOCKED', 'This declaration is already validated.');
            }

            $company = Company::query()->findOrFail($declaration->company_id);
            $reference = sprintf('%s-%s-%d', $declaration->format_code, DB::table('sites')->where('id', $declaration->site_id)->value('code'), $declaration->reporting_year);
            $file = $this->files->storeContent($this->pdf($declaration), "{$reference}.pdf", 'application/pdf', $company, 'declarations', $actor->id, now()->addYears(10));

            $document = Document::query()->create([
                'document_type' => DocumentType::AnnualDeclaration, 'title' => "Déclaration annuelle {$declaration->reporting_year}", 'reference_number' => $reference,
                'status' => DocumentStatus::Draft, 'is_regulatory' => true, 'site_id' => $declaration->site_id,
                'period_start' => "{$declaration->reporting_year}-01-01", 'period_end' => "{$declaration->reporting_year}-12-31",
                'uploaded_by_company_id' => $company->id, 'stored_file_id' => $file->id, 'checksum_sha256' => $file->checksum_sha256, 'source_snapshot' => $declaration->data,
            ]);
            $this->issue->handle($document);

            $declaration->forceFill(['status' => Status::Validated, 'validated_at' => now(), 'validated_by_user_id' => $actor->id,
                'document_id' => $document->id, 'submission_reference' => $reference])->save();

            return $declaration;
        });
    }

    public function pdf(AnnualDeclaration $declaration): string
    {
        return Pdf::loadView('pdf.declaration', [
            'declaration' => $declaration, 'data' => $declaration->data, 'logo' => null,
            'company' => Company::query()->findOrFail($declaration->company_id),
            'title' => "Déclaration annuelle des déchets {$declaration->reporting_year}",
            'generatedAt' => now()->timezone((string) DB::table('companies')->where('id', $declaration->company_id)->value('timezone')),
        ])->setPaper('a4', 'landscape')->output();
    }

    /**
     * @return array<string, mixed>
     */
    private function compute(Site $site, int $year, Company $company): array
    {
        $start = Carbon::create($year, 1, 1, 0, 0, 0, $company->timezone)->utc();
        $end = $start->copy()->addYear();

        $produced = DB::table('waste_lots as l')->join('waste_types as t', 't.id', '=', 'l.waste_type_id')
            ->leftJoin('regulatory_waste_codes as rc', 'rc.id', '=', 'l.regulatory_waste_code_id')
            ->where('l.company_id', $site->company_id)->where('l.site_id', $site->id)->where('l.origin_type', 'created')->whereNull('l.deleted_at')
            ->where('l.generated_at', '>=', $start)->where('l.generated_at', '<', $end)
            ->groupBy('rc.code', 't.name', 'l.is_hazardous')
            ->get(['rc.code', 't.name', 'l.is_hazardous', DB::raw('SUM(l.net_weight_kg) as kg'), DB::raw('COUNT(*) as lots')]);

        $evacuated = DB::table('pickup_lots as pl')->join('pickups as p', 'p.id', '=', 'pl.pickup_id')->join('companies as c', 'c.id', '=', 'p.provider_company_id')
            ->leftJoin('treatment_channels as ch', 'ch.id', '=', 'pl.actual_treatment_channel_id')
            ->where('p.company_id', $site->company_id)->where('p.site_id', $site->id)->whereIn('pl.line_status', ['loaded', 'received', 'treated'])
            ->where('p.collected_at', '>=', $start)->where('p.collected_at', '<', $end)
            ->groupBy('pl.regulatory_code_snapshot', 'pl.waste_type_label_snapshot', 'pl.is_hazardous', 'c.legal_name', 'ch.code')
            ->get(['pl.regulatory_code_snapshot as code', 'pl.waste_type_label_snapshot as name', 'pl.is_hazardous', 'c.legal_name as provider', 'ch.code as channel',
                DB::raw('SUM(COALESCE(pl.received_weight_kg, pl.departure_weight_kg)) as kg')]);

        // Still on site when the year ended: produced before the end and neither removed nor absorbed by a split/grouping before it.
        $stock = DB::table('waste_lots as l')
            ->where('l.company_id', $site->company_id)->where('l.site_id', $site->id)->whereNull('l.deleted_at')->where('l.generated_at', '<', $end)
            ->where(fn ($query) => $query->whereNull('l.collected_at')->orWhere('l.collected_at', '>=', $end))
            ->where(fn ($query) => $query->whereNull('l.closed_at')->orWhere('l.closed_at', '>=', $end)->orWhere('l.closed_reason', 'completed'))
            ->sum('l.net_weight_kg');

        $kg = fn ($value): string => bcadd((string) ($value ?: '0'), '0', 3);
        $line = fn (\stdClass $row, array $extra): array => ['regulatory_code' => $row->code, 'waste_type' => $row->name, 'is_hazardous' => (bool) $row->is_hazardous, 'kg' => $kg($row->kg)] + $extra;

        return [
            'format' => 'TN_ANGED',
            'year' => $year,
            'producer' => ['legal_name' => $company->legal_name, 'tax_id' => $company->tax_id, 'site' => $site->name, 'site_code' => $site->code,
                'address' => trim("{$site->address_line1} {$site->postal_code} {$site->city}"), 'regulatory_identifier' => $site->regulatory_identifier],
            'produced' => $produced->map(fn (\stdClass $row) => $line($row, ['lots' => (int) $row->lots]))->sortByDesc('kg', SORT_NUMERIC)->values()->all(),
            'evacuated' => $evacuated->map(fn (\stdClass $row) => $line($row, ['provider' => $row->provider, 'channel' => $row->channel]))->sortByDesc('kg', SORT_NUMERIC)->values()->all(),
            'totals' => [
                'produced_kg' => $kg($produced->sum('kg')),
                'hazardous_produced_kg' => $kg($produced->where('is_hazardous', 1)->sum('kg')),
                'evacuated_kg' => $kg($evacuated->sum('kg')),
                'stock_at_year_end_kg' => $kg($stock),
            ],
        ];
    }
}
