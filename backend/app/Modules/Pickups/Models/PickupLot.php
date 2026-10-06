<?php

namespace App\Modules\Pickups\Models;

use App\Modules\Tenancy\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Lot line with a snapshot (providers never read waste_lots), weights, channel and price.
 */
class PickupLot extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id', 'company_id'];

    /**
     * @return list<string>
     */
    public function partyColumns(): array
    {
        return ['provider_company_id', 'transporter_company_id'];
    }

    protected function casts(): array
    {
        return ['is_hazardous' => 'boolean', 'is_active_flag' => 'boolean', 'variance_flagged' => 'boolean',
            'declared_weight_kg' => 'decimal:3', 'departure_weight_kg' => 'decimal:3', 'received_weight_kg' => 'decimal:3'];
    }
}
