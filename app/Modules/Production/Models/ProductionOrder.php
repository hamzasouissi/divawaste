<?php

namespace App\Modules\Production\Models;

use App\Modules\Audit\Models\Concerns\Auditable;
use App\Modules\Common\Models\Concerns\HasPublicUlid;
use App\Modules\Tenancy\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Ordre de fabrication (OF): an article to make, with the stages it goes through and what it consumes.
 *
 * @property string $ulid
 */
class ProductionOrder extends Model
{
    use Auditable;
    use BelongsToCompany;
    use HasPublicUlid;

    protected $fillable = ['ulid', 'order_number', 'article_id', 'customer_order_id', 'quantity_planned', 'quantity_produced', 'started_on', 'finished_on', 'status', 'notes'];

    protected function casts(): array
    {
        return ['started_on' => 'date', 'finished_on' => 'date', 'quantity_planned' => 'integer', 'quantity_produced' => 'integer'];
    }
}
