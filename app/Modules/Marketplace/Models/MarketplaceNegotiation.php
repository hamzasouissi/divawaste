<?php

namespace App\Modules\Marketplace\Models;

use App\Modules\Audit\Models\Concerns\Auditable;
use App\Modules\Common\Models\Concerns\HasPublicUlid;
use App\Modules\Tenancy\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The private exchange between the seller of a listing and one buyer: proposals in turn until one side accepts or
 * refuses. Owned by the seller; the buyer sees its own through partyColumns(), never another buyer's.
 *
 * @property string $ulid
 */
class MarketplaceNegotiation extends Model
{
    use Auditable;
    use BelongsToCompany;
    use HasPublicUlid;

    /** Still being negotiated: someone has to answer. */
    public const OPEN = ['awaiting_seller', 'awaiting_buyer'];

    protected $guarded = ['id', 'company_id'];

    protected $attributes = ['status' => 'awaiting_seller'];

    /**
     * @return list<string>
     */
    public function partyColumns(): array
    {
        return ['provider_company_id'];
    }

    protected function casts(): array
    {
        return ['accepted_at' => 'datetime', 'ended_at' => 'datetime', 'commission_pct' => 'decimal:2'];
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    /**
     * @return BelongsTo<MarketplaceListing, $this>
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(MarketplaceListing::class, 'marketplace_listing_id');
    }

    /**
     * @return HasMany<MarketplaceProposal, $this>
     */
    public function proposals(): HasMany
    {
        return $this->hasMany(MarketplaceProposal::class)->orderBy('sequence');
    }
}
