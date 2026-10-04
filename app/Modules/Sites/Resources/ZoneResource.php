<?php

namespace App\Modules\Sites\Resources;

use App\Modules\Common\Support\ReferenceCodes;
use App\Modules\Sites\Models\Zone;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Zone
 */
class ZoneResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'code' => $this->code,
            'name' => $this->name,
            'zone_type' => app(ReferenceCodes::class)->code('zone_types', $this->zone_type_id),
            'capacity_kg' => $this->capacity_kg,
            'capacity_m3' => $this->capacity_m3,
            'capacity_alert_pct' => $this->capacity_alert_pct,
            'is_waste_storage' => $this->is_waste_storage,
            'is_active' => $this->is_active,
        ];
    }
}
