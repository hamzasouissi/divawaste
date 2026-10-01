<?php

namespace App\Modules\Catalog\Resources;

use App\Modules\Catalog\Models\WasteType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin WasteType
 */
class WasteTypeResource extends JsonResource
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
            'description' => $this->description,
            'is_hazardous' => $this->is_hazardous,
            'default_grammage_gsm' => $this->default_grammage_gsm,
            'default_composition' => $this->default_composition,
            'density_kg_m3' => $this->density_kg_m3,
            'unit_weight_kg' => $this->unit_weight_kg,
            'extra_attributes' => $this->extra_attributes,
            'is_active' => $this->is_active,
        ];
    }
}
