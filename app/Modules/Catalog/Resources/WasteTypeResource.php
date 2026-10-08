<?php

namespace App\Modules\Catalog\Resources;

use App\Modules\Catalog\Models\WasteType;
use App\Modules\Common\Support\ReferenceCodes;
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
        $codes = app(ReferenceCodes::class);

        return [
            'ulid' => $this->ulid,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'catalog_item' => $codes->code('waste_catalog_items', $this->waste_catalog_item_id),
            'waste_family' => $codes->code('waste_families', $this->waste_family_id),
            'regulatory_code' => $codes->code('regulatory_waste_codes', $this->regulatory_waste_code_id),
            'is_hazardous' => $this->is_hazardous,
            // This waste only leaves the site under an authorisation (declaration signed by customs) that still has room.
            'requires_authorization' => $this->requires_authorization,
            // M1-05: weights are stored in kg; the unit is how this type is usually counted.
            'unit' => $codes->code('units', $this->default_unit_id),
            'packaging_type' => $codes->code('packaging_types', $this->default_packaging_type_id),
            'color_family' => $codes->code('color_families', $this->default_color_family_id),
            'treatment_channel' => $codes->code('treatment_channels', $this->default_treatment_channel_id),
            'default_grammage_gsm' => $this->default_grammage_gsm,
            'default_composition' => $this->default_composition,
            'density_kg_m3' => $this->density_kg_m3,
            'unit_weight_kg' => $this->unit_weight_kg,
            'extra_attributes' => $this->extra_attributes,
            'is_active' => $this->is_active,
        ];
    }
}
