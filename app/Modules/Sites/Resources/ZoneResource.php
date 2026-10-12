<?php

namespace App\Modules\Sites\Resources;

use App\Modules\Common\Support\ReferenceCodes;
use App\Modules\Sites\Models\Zone;
use App\Modules\Sites\Support\ZoneOrganisation;
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
        $organisation = app(ZoneOrganisation::class);
        $rules = $organisation->of($this->id);

        return [
            'ulid' => $this->ulid,
            'code' => $this->code,
            'name' => $this->name,
            'zone_type' => app(ReferenceCodes::class)->code('zone_types', $this->zone_type_id),
            'capacity_kg' => $this->capacity_kg,
            'capacity_m3' => $this->capacity_m3,
            // How the room is measured: 'packaging' (packages per packaging, sharing one room) or 'volume' (m³). Never both.
            'space_method' => $this->space_method ?? 'packaging',
            // A volume is on record for the zone but its room is measured in packages: the volume is kept, and not used.
            // (Volumes entered before the room could be measured in volume are in this case until someone reviews them.)
            'inactive_volume' => ($this->space_method ?? 'packaging') !== 'volume' && $this->capacity_m3 !== null && bccomp((string) $this->capacity_m3, '0', 3) > 0,
            'capacity_alert_pct' => $this->capacity_alert_pct,
            'is_waste_storage' => $this->is_waste_storage,
            'is_active' => $this->is_active,
            'capacities' => $rules['capacities'],
            'accepted_waste_types' => $rules['accepted_waste_types'],
            'accepted_colors' => $rules['accepted_colors'],
            'fill' => $organisation->fill($this->site_id, $this->id, $this->capacity_kg, $this->capacity_alert_pct, $this->capacity_m3, $this->space_method ?? 'packaging'),
        ];
    }
}
