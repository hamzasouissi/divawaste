<?php

namespace App\Modules\Sites\Resources;

use App\Modules\Sites\Models\Site;
use App\Modules\Sites\Support\ZoneOrganisation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

/**
 * @mixin Site
 */
class SiteResource extends JsonResource
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
            'site_kind' => $this->site_kind,
            'address_line1' => $this->address_line1,
            'city' => $this->city,
            'postal_code' => $this->postal_code,
            'region' => $this->region,
            'timezone' => $this->timezone,
            'regulatory_identifier' => $this->regulatory_identifier,
            'capacity_kg' => $this->capacity_kg,
            'capacity_alert_pct' => $this->capacity_alert_pct ?? 90,
            // The weight on the site against the weight it may hold: every lot once, whatever its zone.
            'load' => app(ZoneOrganisation::class)->siteLoad($this->id, $this->capacity_kg, (int) ($this->capacity_alert_pct ?? 90)),
            'is_active' => $this->is_active,
            'zones_count' => $this->whenCounted('zones'),
            // Zones with a volume on record that their room does not use: to be reviewed by whoever manages the zones.
            'inactive_volume_zones' => DB::table('zones')->where('site_id', $this->id)->where('is_active', true)->where('space_method', '!=', 'volume')->where('capacity_m3', '>', 0)->count(),
            'zones' => ZoneResource::collection($this->whenLoaded('zones')),
        ];
    }
}
