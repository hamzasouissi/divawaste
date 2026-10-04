<?php

namespace App\Modules\Documents\Actions;

use App\Modules\Documents\DTOs\CreateAnnualDeclarationData;
use App\Modules\Documents\Enums\AnnualDeclarationStatus;
use App\Modules\Documents\Models\AnnualDeclaration;
use App\Modules\Identity\Models\User;
use App\Modules\Sites\Models\Site;
use Illuminate\Support\Facades\DB;

class CreateAnnualDeclaration
{
    public function handle(Site $site, CreateAnnualDeclarationData $data, User $actor): AnnualDeclaration
    {
        return DB::transaction(function () use ($site, $data): AnnualDeclaration {
            $status = $data->status ?? AnnualDeclarationStatus::Draft->value;

            $declaration = AnnualDeclaration::query()->create([
                'company_id' => $site->company_id,
                'site_id' => $site->id,
                'format_code' => $data->formatCode,
                'reporting_year' => $data->reportingYear,
                'status' => $status,
                'data' => $data->data ?? [],
                'computed_at' => now(),
                'submission_reference' => sprintf('%s-%s-%s', strtoupper($data->formatCode), $site->code, $data->reportingYear),
            ]);

            return $declaration->fresh(['site', 'document']);
        });
    }
}
