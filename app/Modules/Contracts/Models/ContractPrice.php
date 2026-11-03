<?php

namespace App\Modules\Contracts\Models;

use App\Modules\Tenancy\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * A price of a contract, for one waste type or (waste_type_id null) for every other one.
 */
class ContractPrice extends Model
{
    use BelongsToCompany;

    protected $fillable = ['contract_id', 'waste_type_id', 'pricing_mode', 'unit_price', 'direction'];

    protected function casts(): array
    {
        return ['unit_price' => 'decimal:3'];
    }
}
