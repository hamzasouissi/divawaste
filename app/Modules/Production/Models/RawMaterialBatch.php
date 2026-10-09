<?php

namespace App\Modules\Production\Models;

use App\Modules\Audit\Models\Concerns\Auditable;
use App\Modules\Common\Models\Concerns\HasPublicUlid;
use App\Modules\Tenancy\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Lot reçu: one delivery of a raw material by a supplier, with what it cost. Kept for traceability; it is not a stock.
 *
 * @property string $ulid
 */
class RawMaterialBatch extends Model
{
    use Auditable;
    use BelongsToCompany;
    use HasPublicUlid;

    protected $fillable = ['ulid', 'raw_material_id', 'production_supplier_id', 'batch_number', 'supplier_batch_reference', 'received_on', 'quantity', 'unit_price', 'total_amount', 'delivery_cost', 'currency_code', 'tax_rate_pct', 'delivery_note', 'invoice_reference', 'external_reference', 'import_source', 'notes'];

    protected function casts(): array
    {
        return ['received_on' => 'date', 'quantity' => 'decimal:3', 'unit_price' => 'decimal:4', 'total_amount' => 'decimal:3', 'delivery_cost' => 'decimal:3', 'tax_rate_pct' => 'decimal:2'];
    }
}
