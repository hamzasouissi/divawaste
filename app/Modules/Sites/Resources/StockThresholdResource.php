<?php

namespace App\Modules\Sites\Resources;

use App\Modules\Catalog\Support\UnitConversion;
use App\Modules\Common\Support\Measure;
use App\Modules\Common\Support\ReferenceCodes;
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
            'site_name' => $this->site?->name,
            'zone' => $this->zone?->ulid,
            'zone_name' => $this->zone?->name,
            'waste_type' => $this->wasteType?->ulid,
            'waste_type_name' => $this->wasteType?->name,
            // The limit in its own unit; `packaging_type` says which packages a limit in packages counts.
            'max_quantity' => $this->max_quantity,
            'unit' => $this->unit_code,
            'packaging_type' => app(ReferenceCodes::class)->code('packaging_types', $this->packaging_type_id),
            // The same limit in kilograms when it is a weight; null otherwise.
            'max_quantity_kg' => Measure::dimension((string) $this->unit_code) === 'mass' ? UnitConversion::convert((string) $this->max_quantity, $this->unit_code, 'kg') : null,
            'warning_pct' => $this->warning_pct,
            'alert_level' => $this->alert_level,
            'alerted_at' => $this->alerted_at?->toIso8601ZuluString(),
            'is_active' => $this->is_active,
        ];
    }
}
