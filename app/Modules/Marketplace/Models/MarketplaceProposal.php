<?php

namespace App\Modules\Marketplace\Models;

use App\Modules\Common\Models\Concerns\HasPublicUlid;
use App\Modules\Tenancy\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * One proposal of a negotiation: a price (signed by `direction`), a pickup date, a message. Never changed afterwards;
 * an answer is a new proposal.
 *
 * @property string $ulid
 */
class MarketplaceProposal extends Model
{
    use BelongsToCompany;
    use HasPublicUlid;

    protected $guarded = ['id', 'company_id'];

    /**
     * @return list<string>
     */
    public function partyColumns(): array
    {
        return ['provider_company_id'];
    }

    protected function casts(): array
    {
        return ['pickup_date' => 'date', 'expires_at' => 'datetime', 'sequence' => 'integer'];
    }

    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
