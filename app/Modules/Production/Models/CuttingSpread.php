<?php

namespace App\Modules\Production\Models;

use App\Modules\Audit\Models\Concerns\Auditable;
use App\Modules\Common\Models\Concerns\HasPublicUlid;
use App\Modules\Tenancy\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * One spread (matelas) of a fabric cut for an OF: its placement and its layers, planned then done.
 *
 * @property string $ulid
 * @property list<array{size: string, pieces: int}> $ratio
 */
class CuttingSpread extends Model
{
    use Auditable;
    use BelongsToCompany;
    use HasPublicUlid;

    protected $fillable = ['ulid', 'production_order_id', 'raw_material_id', 'raw_material_batch_id', 'marker_length_m', 'efficiency_pct', 'end_allowance_cm', 'ratio',
        'layers_planned', 'layers_done', 'cut_on', 'notes', 'consumption_recorded_at'];

    protected function casts(): array
    {
        return ['ratio' => 'array', 'cut_on' => 'date', 'consumption_recorded_at' => 'datetime'];
    }
}
