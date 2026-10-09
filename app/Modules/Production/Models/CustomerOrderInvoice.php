<?php

namespace App\Modules\Production\Models;

use App\Modules\Audit\Models\Concerns\Auditable;
use App\Modules\Common\Models\Concerns\HasPublicUlid;
use App\Modules\Tenancy\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * An invoice or a credit note issued for an order of a client. Issued elsewhere: only its number, date and amount are recorded here.
 *
 * @property string $ulid
 */
class CustomerOrderInvoice extends Model
{
    use Auditable;
    use BelongsToCompany;
    use HasPublicUlid;

    protected $fillable = ['ulid', 'customer_order_id', 'production_order_id', 'kind', 'number', 'issued_on', 'amount', 'currency_code', 'notes', 'import_source'];

    protected function casts(): array
    {
        return ['issued_on' => 'date', 'amount' => 'decimal:3'];
    }
}
