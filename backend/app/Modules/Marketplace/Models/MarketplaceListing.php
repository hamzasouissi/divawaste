<?php

namespace App\Modules\Marketplace\Models;

use App\Modules\Audit\Models\Concerns\Auditable;
use App\Modules\Common\Models\Concerns\HasPublicUlid;
use App\Modules\Tenancy\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Lots of one site and one waste type that their producer offers on the bourse (M4-06). Owned by the seller; buyers
 * read published listings through MarketplaceCatalog, never through this model.
 *
 * @property string $ulid
 */
class MarketplaceListing extends Model
{
    use Auditable;
    use BelongsToCompany;
    use HasPublicUlid;

    /** A listing whose lots are reserved: nothing else may be done with them but the deal. */
    public const ACTIVE = ['draft', 'published'];

    protected $guarded = ['id', 'company_id'];

    protected $attributes = ['status' => 'draft', 'show_seller' => false];

    protected function casts(): array
    {
        return ['show_seller' => 'boolean', 'available_from' => 'date', 'closes_at' => 'datetime', 'published_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    /**
     * @return HasMany<MarketplaceListingLot, $this>
     */
    public function lots(): HasMany
    {
        return $this->hasMany(MarketplaceListingLot::class);
    }

    /**
     * @return HasMany<MarketplaceNegotiation, $this>
     */
    public function negotiations(): HasMany
    {
        return $this->hasMany(MarketplaceNegotiation::class);
    }
}
