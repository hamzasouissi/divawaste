<?php

namespace App\Modules\Contracts\Models;

use App\Modules\Tenancy\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * One charge of a contract beside its prices: a name, an amount, when it is due (per pickup, per tonne received, per
 * month, once) and who pays whom.
 */
class ContractCharge extends Model
{
    use BelongsToCompany;

    public const BASES = ['per_pickup', 'per_tonne', 'per_month', 'once'];

    protected $fillable = ['contract_id', 'label', 'amount', 'basis', 'direction', 'position'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:3', 'position' => 'integer'];
    }
}
