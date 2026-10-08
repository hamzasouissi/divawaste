<?php

namespace App\Modules\Production\Models;

use App\Modules\Audit\Models\Concerns\Auditable;
use App\Modules\Common\Models\Concerns\HasPublicUlid;
use App\Modules\Tenancy\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * A raw material (fabric, yarn, accessory): its supplier, composition and colour are copied onto the waste that comes from it.
 *
 * @property string $ulid
 */
class RawMaterial extends Model
{
    use Auditable;
    use BelongsToCompany;
    use HasPublicUlid;

    protected $fillable = ['ulid', 'reference', 'name', 'production_supplier_id', 'unit_id', 'color_family_id', 'color_label', 'grammage_gsm', 'width_cm', 'composition', 'notes', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'composition' => 'array', 'grammage_gsm' => 'decimal:2', 'width_cm' => 'decimal:2'];
    }
}
