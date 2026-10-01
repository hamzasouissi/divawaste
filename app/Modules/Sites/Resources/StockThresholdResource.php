<?php

namespace App\Modules\Sites\Resources;

use App\Modules\Sites\Models\StockThreshold;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StockThreshold
 */
class StockThresholdResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'site' => $this->site?->ulid,
            'zone' => $this->zone?->ulid,
            'waste_type' => $this->wasteType?->ulid,
            'max_quantity_kg' => $this->max_quantity_kg,
            'warning_pct' => $this->warning_pct,
            'alert_level' => $this->alert_level,
            'alerted_at' => $this->alerted_at?->toIso8601ZuluString(),
            'is_active' => $this->is_active,
        ];
    }
}
