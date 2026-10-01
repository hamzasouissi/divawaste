<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Common\Models\Concerns\HasPublicUlid;
use App\Modules\Tenancy\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Company waste type, activated from the platform catalog (copied defaults) or custom (M1-02..05).
 */
class WasteType extends Model
{
    use BelongsToCompany;
    use HasPublicUlid;
    use SoftDeletes;

    protected $attributes = ['is_hazardous' => false, 'is_active' => true];

    protected $fillable = [
        'ulid', 'waste_catalog_item_id', 'code', 'name', 'description', 'waste_family_id', 'regulatory_waste_code_id',
        'is_hazardous', 'default_unit_id', 'default_packaging_type_id', 'default_color_family_id', 'default_grammage_gsm',
        'default_treatment_channel_id', 'default_composition', 'density_kg_m3', 'unit_weight_kg', 'extra_attributes',
        'activated_at', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'is_hazardous' => 'boolean',
            'is_active' => 'boolean',
            'default_grammage_gsm' => 'decimal:2',
            'default_composition' => 'array',
            'density_kg_m3' => 'decimal:3',
            'unit_weight_kg' => 'decimal:3',
            'extra_attributes' => 'array',
            'activated_at' => 'datetime',
        ];
    }
}
