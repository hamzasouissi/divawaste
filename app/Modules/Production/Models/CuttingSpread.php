<?php

namespace App\Modules\Production\Models;

use App\Modules\Audit\Models\Concerns\Auditable;
use App\Modules\Common\Models\Concerns\HasPublicUlid;
use App\Modules\Tenancy\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * One spread (matelas) of a fabric cut for an OF: its length, its layers planned then done, the efficiency of its
 * placement, and the laize and grammage of the rolls laid when they are not the raw material's.
 *
 * @property string $ulid
 */
class CuttingSpread extends Model
{
    use Auditable;
    use BelongsToCompany;
    use HasPublicUlid;

    protected $fillable = ['ulid', 'production_order_id', 'raw_material_id', 'length_m', 'efficiency_pct', 'width_cm', 'grammage_gsm',
        'layers_planned', 'layers_done', 'cut_on', 'notes'];

    protected function casts(): array
    {
        return ['cut_on' => 'date'];
    }
}
