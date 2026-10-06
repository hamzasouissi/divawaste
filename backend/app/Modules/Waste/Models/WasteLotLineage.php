<?php

namespace App\Modules\Waste\Models;

use App\Modules\Tenancy\Models\Concerns\BelongsToCompany;
use App\Modules\Waste\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;

/**
 * Permanent DAG edge: parent (input) → child (output) of a split or grouping.
 */
class WasteLotLineage extends Model
{
    use AppendOnly;
    use BelongsToCompany;

    protected $table = 'waste_lot_lineage';

    public $timestamps = false;

    protected $fillable = ['waste_lot_operation_id', 'parent_lot_id', 'child_lot_id', 'relation_type', 'quantity_kg', 'recorded_at'];

    protected function casts(): array
    {
        return ['quantity_kg' => 'decimal:3', 'recorded_at' => 'immutable_datetime'];
    }
}
