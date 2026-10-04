<?php

namespace App\Modules\Documents\Actions;

use App\Modules\Common\Support\NumberSequence;
use App\Modules\Documents\DTOs\CreateHazardousWasteManifestData;
use App\Modules\Documents\Enums\DocumentStatus;
use App\Modules\Documents\Enums\DocumentType;
use App\Modules\Documents\Enums\HazardousWasteManifestStatus;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\HazardousWasteManifest;
use App\Modules\Identity\Models\User;
use App\Modules\Pickups\Models\Pickup;
use Illuminate\Support\Facades\DB;

class CreateHazardousWasteManifest
{
    public function handle(Pickup $pickup, CreateHazardousWasteManifestData $data, User $actor): HazardousWasteManifest
    {
        return DB::transaction(function () use ($pickup, $data, $actor): HazardousWasteManifest {
            $companyId = $pickup->company_id;
            $siteId = $pickup->site_id;
            $year = now()->year;
            $manifestNumber = NumberSequence::next($companyId, 'manifest', "HM-{$year}-", (string) $year, 4);

            $document = new Document([
                'document_type' => DocumentType::HazardousManifest,
                'title' => 'Hazardous waste manifest',
                'reference_number' => $manifestNumber,
                'status' => DocumentStatus::Draft,
                'site_id' => $siteId,
                'pickup_id' => $pickup->id,
                'is_regulatory' => true,
                'uploaded_by_company_id' => $companyId,
                'source_snapshot' => [
                    'pickup_id' => $pickup->ulid,
                    'actor_user_id' => $actor->id,
                    'trigger' => 'manifest_generation',
                ],
            ]);
            $document->company_id = $companyId;
            $document->save();

            $wasteTypeId = null;
            if ($data->wasteType !== null && $data->wasteType !== '') {
                $wasteTypeId = is_numeric((string) $data->wasteType)
                    ? (int) $data->wasteType
                    : \App\Modules\Catalog\Models\WasteType::query()->where('ulid', (string) $data->wasteType)->value('id');
            }

            $manifest = HazardousWasteManifest::query()->create([
                'company_id' => $companyId,
                'manifest_number' => $manifestNumber,
                'pickup_id' => $pickup->id,
                'site_id' => $siteId,
                'document_id' => $document->id,
                'status' => $data->status ?? 'draft',
                'regulatory_waste_code_id' => $data->regulatoryWasteCodeId,
                'waste_type_id' => $wasteTypeId,
                'package_count' => $data->packageCount,
                'declared_weight_kg' => $data->declaredWeightKg,
                'producer_snapshot' => $data->producerSnapshot,
                'transporter_snapshot' => $data->transporterSnapshot,
                'receiver_snapshot' => $data->receiverSnapshot,
                'transporter_company_id' => $data->transporterCompanyId,
                'receiver_company_id' => $data->receiverCompanyId,
                'collected_at' => now(),
            ]);

            $document->update([
                'status' => DocumentStatus::Draft,
            ]);

            return $manifest->fresh(['pickup', 'site', 'wasteType', 'document']);
        });
    }
}
