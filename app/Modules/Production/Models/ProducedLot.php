<?php

namespace App\Modules\Production\Models;

use App\Modules\Audit\Models\Concerns\Auditable;
use App\Modules\Common\Models\Concerns\HasPublicUlid;
use App\Modules\Tenancy\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Lot fabriqué: finished goods made by a manufacturing order for its client. It has no supplier: suppliers belong to the received lots it consumed.
 *
 * @property string $ulid
 */
class ProducedLot extends Model
{
    use Auditable;
    use BelongsToCompany;
    use HasPublicUlid;

    protected $fillable = ['ulid', 'production_order_id', 'lot_number', 'quantity', 'unit_id', 'produced_on', 'produced_until', 'production_step_id', 'site_id', 'status', 'delivered_on', 'notes', 'external_reference', 'import_source'];

    protected function casts(): array
    {
        return ['produced_on' => 'date', 'produced_until' => 'date', 'delivered_on' => 'date', 'quantity' => 'integer'];
    }
}
