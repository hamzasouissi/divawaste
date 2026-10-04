<?php

namespace App\Modules\Reporting\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Reporting\DTOs\CreateGeneratedReportData;
use App\Modules\Reporting\Models\GeneratedReport;
use App\Modules\Tenancy\TenantContext;
use Carbon\CarbonInterface;

final class CreateGeneratedReport
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(CreateGeneratedReportData $data, User $actor): GeneratedReport
    {
        $companyId = $this->context->companyId();

        $report = GeneratedReport::query()->create([
            'company_id' => $companyId,
            'report_type' => $data->reportType,
            'format' => $data->format,
            'parameters' => $data->parameters,
            'status' => $data->status,
            'requested_by_user_id' => $actor->id,
            'stored_file_id' => $data->storedFileId,
            'started_at' => now(),
            'expires_at' => now()->addDays(30),
        ]);

        return $report->fresh();
    }
}
