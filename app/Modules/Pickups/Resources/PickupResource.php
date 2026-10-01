<?php

namespace App\Modules\Pickups\Resources;

use App\Modules\Pickups\Models\Pickup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Pickup
 */
class PickupResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'pickup_number' => $this->pickup_number,
            'status' => $this->status,
            'requested_date' => $this->requested_date?->toDateString(),
            'confirmed_date' => $this->confirmed_date?->toDateString(),
            'departure_weight_kg' => $this->departure_weight_kg,
            'received_weight_kg' => $this->received_weight_kg,
            'weight_variance_pct' => $this->weight_variance_pct,
            'variance_flagged' => $this->variance_flagged,
            'lots' => $this->lines()->orderBy('id')->get(['lot_number_snapshot', 'waste_type_label_snapshot', 'is_hazardous', 'line_status',
                'declared_weight_kg', 'departure_weight_kg', 'received_weight_kg'])->toArray(),
        ];
    }
}
