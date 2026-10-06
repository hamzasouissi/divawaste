<?php

namespace App\Modules\Reporting\Jobs;

use App\Modules\Common\Exceptions\BusinessRuleViolation;
use App\Modules\Common\Support\FileStorage;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\BusinessEventNotification;
use App\Modules\Reporting\Models\GeneratedReport;
use App\Modules\Reporting\Support\ReportBuilder;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Heavy exports run on the queue (cahier §5): the request returns at once, the requester is notified when
 * the file is ready. The job re-enters the tenant context of the report's company explicitly — a worker has none.
 */
final class GenerateReport implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 2;

    public int $timeout = 300;

    public function __construct(private readonly int $companyId, private readonly int $reportId)
    {
        $this->afterCommit();
    }

    public function handle(TenantContext $context, ReportBuilder $builder, FileStorage $files): void
    {
        $company = Company::query()->findOrFail($this->companyId);

        $context->runAs($company, function () use ($company, $builder, $files) {
            $report = GeneratedReport::query()->findOrFail($this->reportId);
            // Idempotent: a retried job never produces a second file.
            if ($report->status === 'completed') {
                return;
            }
            $report->forceFill(['status' => 'processing', 'started_at' => now()])->save();

            try {
                $built = $builder->build($report->report_type, $report->format, $report->parameters, $company);
                $file = $files->storeContent($built['content'], $built['filename'], $built['mime'], $company, 'reports', $report->requested_by_user_id, $report->expires_at);
                $report->forceFill(['status' => 'completed', 'stored_file_id' => $file->id, 'completed_at' => now(), 'error_message' => null])->save();
                $this->tell($report, 'report.ready');
            } catch (BusinessRuleViolation $e) {
                // A business refusal (too many rows…) will not succeed on retry: record it and stop.
                $this->markFailed($report, $e->getMessage());
            }
        });
    }

    public function failed(Throwable $e): void
    {
        $company = Company::query()->find($this->companyId);
        if ($company === null) {
            return;
        }

        app(TenantContext::class)->runAs($company, function () {
            $report = GeneratedReport::query()->find($this->reportId);
            if ($report !== null && $report->status !== 'completed') {
                $this->markFailed($report, 'La génération a échoué. Réessayez ou réduisez la période.');
            }
        });
    }

    private function markFailed(GeneratedReport $report, string $message): void
    {
        $report->forceFill(['status' => 'failed', 'error_message' => mb_substr($message, 0, 1000), 'completed_at' => now()])->save();
        $this->tell($report, 'report.failed');
    }

    private function tell(GeneratedReport $report, string $type): void
    {
        User::query()->find($report->requested_by_user_id)?->notify(new BusinessEventNotification(
            $type, ['name' => __("exports.report.{$report->report_type}")], '/reports', $report->company_id,
        ));
    }
}
