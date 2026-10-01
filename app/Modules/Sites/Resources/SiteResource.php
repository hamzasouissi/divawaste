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
            'city' => $this->city,
            'region' => $this->region,
            'timezone' => $this->timezone,
            'capacity_kg' => $this->capacity_kg,
            'is_active' => $this->is_active,
        ];
    }
}
