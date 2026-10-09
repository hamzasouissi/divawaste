<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Audit\Models\Concerns\Auditable;
use App\Modules\Catalog\Support\UnitConversion;
use App\Modules\Common\Models\Concerns\HasPublicUlid;
use App\Modules\Tenancy\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Company waste type, activated from the platform catalog (copied defaults) or custom (M1-02..05).
 *
 * @property string $ulid
 */
class WasteType extends Model
{
    use Auditable;
    use BelongsToCompany;
    use HasPublicUlid;
    use SoftDeletes;

    /** How the quantity of a lot is obtained: on a scale, by counting, by measuring a volume, or by estimate. */
    public const METHODS = ['weighed', 'counted', 'measured', 'estimated'];

    protected $attributes = ['is_hazardous' => false, 'requires_authorization' => false, 'is_active' => true, 'measurement_method' => 'weighed'];

    protected $fillable = [
        'ulid', 'waste_catalog_item_id', 'code', 'name', 'description', 'waste_family_id', 'regulatory_waste_code_id',
        'is_hazardous', 'default_unit_id', 'default_packaging_type_id', 'default_color_family_id', 'default_grammage_gsm',
        'default_treatment_channel_id', 'default_composition', 'density_kg_m3', 'unit_weight_kg', 'extra_attributes', 'measurement_method',
        'activated_at', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'is_hazardous' => 'boolean',
            'requires_authorization' => 'boolean',
            'is_active' => 'boolean',
            'default_grammage_gsm' => 'decimal:2',
            'default_composition' => 'array',
            'density_kg_m3' => 'decimal:3',
            'unit_weight_kg' => 'decimal:3',
            'extra_attributes' => 'array',
            'activated_at' => 'datetime',
        ];
    }

    /** What a type counted in this unit is, unless the company says otherwise. */
    public static function defaultMethod(?string $unit): string
    {
        return match (UnitConversion::dimension($unit)) {
            'count' => 'counted',
            'volume' => 'measured',
            default => 'weighed',
        };
    }
}
