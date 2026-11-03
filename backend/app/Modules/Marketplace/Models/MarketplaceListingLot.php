<?php

namespace App\Modules\Marketplace\Models;

use App\Modules\Tenancy\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * A lot of a listing. `is_active_flag` is true while the listing is a draft or published: a lot is offered once at a time.
 */
class MarketplaceListingLot extends Model
{
    use BelongsToCompany;

    protected $fillable = ['marketplace_listing_id', 'waste_lot_id', 'is_active_flag'];

    protected function casts(): array
    {
        return ['is_active_flag' => 'boolean'];
    }
}
