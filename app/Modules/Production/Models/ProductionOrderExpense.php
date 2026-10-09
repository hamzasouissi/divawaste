<?php

namespace App\Modules\Production\Models;

use App\Modules\Audit\Models\Concerns\Auditable;
use App\Modules\Common\Models\Concerns\HasPublicUlid;
use App\Modules\Tenancy\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * A cost of a manufacturing order beside its materials: subcontracting, a service, a purchase, transport.
 *
 * @property string $ulid
 */
class ProductionOrderExpense extends Model
{
    use Auditable;
    use BelongsToCompany;
    use HasPublicUlid;

    protected $fillable = ['ulid', 'production_order_id', 'category', 'description', 'amount', 'currency_code', 'incurred_on', 'production_supplier_id', 'reference', 'notes', 'import_source'];

    protected function casts(): array
    {
        return ['incurred_on' => 'date', 'amount' => 'decimal:3'];
    }
}
