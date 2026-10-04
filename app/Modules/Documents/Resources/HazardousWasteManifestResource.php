<?php

namespace App\Modules\Documents\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class HazardousWasteManifestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'manifest_number' => $this->manifest_number,
            'status' => $this->status?->value ?? $this->status,
            'pickup_id' => $this->pickup?->ulid ?? $this->pickup_id,
            'site_id' => $this->site?->ulid ?? $this->site_id,
            'document_id' => $this->document?->ulid ?? $this->document_id,
            'regulatory_waste_code_id' => $this->regulatory_waste_code_id,
            'waste_type_id' => $this->wasteType?->ulid ?? $this->waste_type_id,
            'package_count' => $this->package_count,
            'declared_weight_kg' => $this->declared_weight_kg,
            'producer_snapshot' => $this->producer_snapshot,
            'transporter_snapshot' => $this->transporter_snapshot,
            'receiver_snapshot' => $this->receiver_snapshot,
            'transporter_company_id' => $this->transporter_company_id,
            'receiver_company_id' => $this->receiver_company_id,
            'collected_at' => $this->collected_at?->toISOString(),
            'received_at' => $this->received_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
