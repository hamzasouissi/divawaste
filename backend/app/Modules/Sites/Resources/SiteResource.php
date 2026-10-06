<?php

namespace App\Modules\Sites\Resources;

use App\Modules\Sites\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

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
            'is_active' => $this->is_active,
            'zones_count' => $this->whenCounted('zones'),
            'zones' => ZoneResource::collection($this->whenLoaded('zones')),
        ];
    }
}
