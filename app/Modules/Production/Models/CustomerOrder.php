<?php

namespace App\Modules\Production\Models;

use App\Modules\Audit\Models\Concerns\Auditable;
use App\Modules\Common\Models\Concerns\HasPublicUlid;
use App\Modules\Tenancy\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * An order of a client: articles and quantities.
 *
 * @property string $ulid
 */
class CustomerOrder extends Model
{
    use Auditable;
    use BelongsToCompany;
    use HasPublicUlid;

    protected $fillable = ['ulid', 'order_number', 'production_client_id', 'ordered_on', 'due_on', 'status', 'notes'];

    protected function casts(): array
    {
        return ['ordered_on' => 'date', 'due_on' => 'date'];
    }
}
