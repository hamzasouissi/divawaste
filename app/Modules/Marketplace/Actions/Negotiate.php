<?php

namespace App\Modules\Marketplace\Actions;

use App\Modules\Common\Exceptions\BusinessRuleViolation;
use App\Modules\Identity\Models\User;
use App\Modules\Marketplace\Models\MarketplaceListing;
use App\Modules\Marketplace\Models\MarketplaceNegotiation;
use App\Modules\Marketplace\Models\MarketplaceProposal;
use App\Modules\Marketplace\Support\MarketplaceDeal;
use App\Modules\Marketplace\Support\MarketplaceEligibility;
use App\Modules\Notifications\Actions\NotifyUsers;
use App\Modules\Pickups\Actions\PickupWorkflow;
use App\Modules\Providers\Models\ProviderPartnership;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\TenantContext;
use App\Modules\Waste\Enums\LotStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The negotiation of the bourse (M4-06), between the seller of a listing and one buyer, in private. A buyer opens it
 * with an offer; then each side answers the other's last proposal in turn: it accepts it as it is, proposes something
 * else, or refuses. The buyer may withdraw its own offer while the seller has not answered it.
 *
 * Accepting is checked again at that moment — the listing still open, the proposal still standing and made on the lots
 * as they are now, the buyer still eligible — and it makes the deal: the listing is sold, the other buyers are told
 * (never the price), the buyer becomes a partner of the seller, and a draft pickup of the lots to that buyer is created
 * at the agreed price, which the seller sends as any pickup request.
 */
final class Negotiate
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly MarketplaceEligibility $eligibility,
        private readonly NotifyUsers $notify,
        private readonly ManageListings $listings,
        private readonly PickupWorkflow $pickups,
    ) {}

    /**
     * The buyer's first offer — or a new one after its earlier offer was refused or withdrawn.
     *
     * @param  array{pricing_mode: string, unit_price: string, direction: string, pickup_date?: string|null, message?: string|null, expires_at?: string|null}  $terms
     */
    public function offer(int $listingId, array $terms, User $actor): MarketplaceNegotiation
    {
        $buyerId = $this->context->companyId();
        $sellerId = (int) DB::table('marketplace_listings')->where('id', $listingId)->value('company_id');

        $negotiation = $this->asSeller($sellerId, fn () => DB::transaction(function () use ($listingId, $buyerId, $terms, $actor) {
            $listing = MarketplaceListing::query()->whereKey($listingId)->lockForUpdate()->firstOrFail();
            $this->assertOpen($listing);
            $this->assertEligible($listing, $buyerId);
            $negotiation = MarketplaceNegotiation::query()->where('marketplace_listing_id', $listing->id)->where('provider_company_id', $buyerId)->lockForUpdate()->first();
            if ($negotiation !== null && $negotiation->isOpen()) {
                throw new BusinessRuleViolation('NEGOTIATION_OPEN', 'You are already negotiating this listing: answer in that negotiation.', ['negotiation' => $negotiation->ulid]);
            }
            $negotiation ??= new MarketplaceNegotiation(['marketplace_listing_id' => $listing->id, 'provider_company_id' => $buyerId]);
            $negotiation->forceFill(['status' => 'awaiting_seller', 'refused_by' => null, 'closed_reason' => null, 'end_message' => null, 'ended_at' => null])->save();
            $this->propose($negotiation, $listing, 'buyer', $terms, $actor);

            return $negotiation;
        }));
        $this->tell($negotiation, 'seller', 'marketplace.offer_received');

        return $negotiation;
    }

    /**
     * The answer of the side whose turn it is: another proposal.
     *
     * @param  array{pricing_mode: string, unit_price: string, direction: string, pickup_date?: string|null, message?: string|null, expires_at?: string|null}  $terms
     */
    public function counter(MarketplaceNegotiation $negotiation, array $terms, User $actor): MarketplaceNegotiation
    {
        $party = $this->party($negotiation);
        $negotiation = $this->asSeller((int) $negotiation->company_id, fn () => DB::transaction(function () use ($negotiation, $party, $terms, $actor) {
            [$negotiation, $listing] = $this->lock($negotiation);
            $this->assertTurn($negotiation, $party);
            $this->assertOpen($listing);
            $this->propose($negotiation, $listing, $party, $terms, $actor);
            $negotiation->forceFill(['status' => $party === 'seller' ? 'awaiting_buyer' : 'awaiting_seller'])->save();

            return $negotiation;
        }));
        $this->tell($negotiation, $party === 'seller' ? 'buyer' : 'seller', 'marketplace.counter_offer');

        return $negotiation;
    }

    /**
     * Accepts the other side's last proposal, named by the caller: what is accepted is what was read, not a proposal
     * that arrived in between.
     */
    public function accept(MarketplaceNegotiation $negotiation, string $proposalUlid, User $actor): MarketplaceNegotiation
    {
        $party = $this->party($negotiation);
        $negotiation = $this->asSeller((int) $negotiation->company_id, fn () => DB::transaction(function () use ($negotiation, $party, $proposalUlid, $actor) {
            [$negotiation, $listing] = $this->lock($negotiation);
            $this->assertTurn($negotiation, $party);
            $proposal = MarketplaceProposal::query()->where('marketplace_negotiation_id', $negotiation->id)->orderByDesc('sequence')->firstOrFail();
            if ($proposal->ulid !== strtoupper($proposalUlid)) {
                throw new BusinessRuleViolation('PROPOSAL_SUPERSEDED', 'This proposal has been answered since: read the last one before accepting.', ['proposal' => $proposal->ulid]);
            }
            if ($proposal->hasExpired()) {
                throw new BusinessRuleViolation('PROPOSAL_EXPIRED', 'This proposal is no longer valid: answer it with a proposal of your own.');
            }
            $this->assertOpen($listing);
            if ($proposal->lots_fingerprint !== MarketplaceDeal::fingerprint((int) $listing->id)) {
                throw new BusinessRuleViolation('PROPOSAL_OUTDATED', 'The lots changed since this proposal was made (weight, packages or composition): it cannot be accepted as it is. Answer it with a new proposal.');
            }
            $this->assertEligible($listing, (int) $negotiation->provider_company_id);
            $lots = DB::table('marketplace_listing_lots as ml')->join('waste_lots as l', 'l.id', '=', 'ml.waste_lot_id')
                ->where('ml.marketplace_listing_id', $listing->id)->get(['l.ulid', 'l.lot_number', 'l.status']);
            foreach ($lots as $lot) {
                if (! in_array($lot->status, [LotStatus::Created->value, LotStatus::Stored->value], true)) {
                    throw new BusinessRuleViolation('LOT_NOT_AVAILABLE', "Lot {$lot->lot_number} is not in stock any more: the listing cannot be sold as it is.");
                }
            }

            $negotiation->forceFill(['status' => 'accepted', 'accepted_proposal_id' => $proposal->id, 'accepted_at' => now(), 'accepted_by_user_id' => $actor->id,
                'commission_pct' => bcadd((string) config('marketplace.commission_pct', '0'), '0', 2), 'ended_at' => now()])->save();
            $listing->forceFill(['status' => 'sold', 'closed_at' => now(), 'accepted_negotiation_id' => $negotiation->id])->save();
            ManageListings::release((int) $listing->id);
            $this->listings->closeNegotiations($listing, 'sold_to_another', (int) $negotiation->id);

            // The buyer becomes a partner of the seller: the pickup of the deal is a pickup like the others.
            $partnership = ProviderPartnership::query()->firstOrCreate(['provider_company_id' => $negotiation->provider_company_id],
                ['status' => 'active', 'started_at' => now(), 'created_by_user_id' => $actor->id]);
            if ($partnership->status !== 'active') {
                $partnership->forceFill(['status' => 'active', 'ended_at' => null])->save();
            }
            $date = $proposal->pickup_date ?? ManageListings::earliestPickup($listing);
            $pickup = $this->pickups->create((int) $listing->site_id, ['provider_company_id' => (int) $negotiation->provider_company_id],
                $date->toDateString(), $lots->pluck('ulid')->all(), $actor);
            $pickup->forceFill(['marketplace_negotiation_id' => $negotiation->id, 'notes' => __('notifications.marketplace.pickup_note', ['number' => $listing->listing_number])])->save();
            $listing->forceFill(['pickup_id' => $pickup->id])->save();

            return $negotiation;
        }));
        $this->tell($negotiation, $party === 'seller' ? 'buyer' : 'seller', 'marketplace.offer_accepted');

        return $negotiation;
    }

    /**
     * Ends the negotiation with a no. The seller may end it at any time; the buyer refuses the seller's proposal when it
     * is its turn (otherwise it withdraws its own offer).
     */
    public function refuse(MarketplaceNegotiation $negotiation, ?string $message, User $actor): MarketplaceNegotiation
    {
        $party = $this->party($negotiation);
        $negotiation = $this->asSeller((int) $negotiation->company_id, fn () => DB::transaction(function () use ($negotiation, $party, $message) {
            [$negotiation] = $this->lock($negotiation);
            if (! $negotiation->isOpen()) {
                throw new BusinessRuleViolation('NEGOTIATION_ENDED', 'This negotiation has already ended.');
            }
            if ($party === 'buyer') {
                $this->assertTurn($negotiation, 'buyer');
            }
            $negotiation->forceFill(['status' => 'refused', 'refused_by' => $party, 'end_message' => $message, 'ended_at' => now()])->save();

            return $negotiation;
        }));
        $this->tell($negotiation, $party === 'seller' ? 'buyer' : 'seller', 'marketplace.offer_refused');

        return $negotiation;
    }

    /**
     * The buyer takes back its offer before the seller answered it.
     */
    public function withdraw(MarketplaceNegotiation $negotiation, ?string $message, User $actor): MarketplaceNegotiation
    {
        if ($this->party($negotiation) !== 'buyer') {
            throw new BusinessRuleViolation('NOT_THE_BUYER', 'Only the buyer withdraws its offer; the seller refuses it.');
        }
        $negotiation = $this->asSeller((int) $negotiation->company_id, fn () => DB::transaction(function () use ($negotiation, $message) {
            [$negotiation] = $this->lock($negotiation);
            if (! $negotiation->isOpen()) {
                throw new BusinessRuleViolation('NEGOTIATION_ENDED', 'This negotiation has already ended.');
            }
            $negotiation->forceFill(['status' => 'withdrawn', 'end_message' => $message, 'ended_at' => now()])->save();

            return $negotiation;
        }));
        $this->tell($negotiation, 'seller', 'marketplace.offer_withdrawn');

        return $negotiation;
    }

    /**
     * The side the current company is on: the owner of the negotiation sells, the provider it is addressed to buys.
     *
     * @return 'seller'|'buyer'
     */
    public function party(MarketplaceNegotiation $negotiation): string
    {
        return $this->context->companyId() === (int) $negotiation->company_id ? 'seller' : 'buyer';
    }

    /**
     * @param  array{pricing_mode: string, unit_price: string, direction: string, pickup_date?: string|null, message?: string|null, expires_at?: string|null}  $terms
     */
    private function propose(MarketplaceNegotiation $negotiation, MarketplaceListing $listing, string $party, array $terms, User $actor): MarketplaceProposal
    {
        $earliest = ManageListings::earliestPickup($listing);
        if (($terms['pickup_date'] ?? null) !== null && Carbon::parse($terms['pickup_date'])->lt($earliest)) {
            throw new BusinessRuleViolation('PICKUP_DATE_TOO_EARLY', 'The lots can be collected from '.$earliest->format('d/m/Y').'.');
        }
        // A proposal stands until the date its author gives, else for the usual days — never after the listing closes.
        $expires = ($terms['expires_at'] ?? null) !== null ? Carbon::parse($terms['expires_at'])->endOfDay() : now()->addDays((int) config('marketplace.proposal_days', 7));
        if ($listing->closes_at !== null && $expires->isAfter($listing->closes_at)) {
            $expires = $listing->closes_at->copy();
        }
        $sequence = (int) MarketplaceProposal::query()->where('marketplace_negotiation_id', $negotiation->id)->max('sequence') + 1;

        return MarketplaceProposal::query()->create([
            'marketplace_negotiation_id' => $negotiation->id, 'provider_company_id' => $negotiation->provider_company_id, 'sequence' => $sequence, 'party' => $party,
            'pricing_mode' => $terms['pricing_mode'], 'unit_price' => bcadd((string) $terms['unit_price'], '0', 3), 'direction' => $terms['direction'],
            'currency_code' => $listing->currency_code, 'pickup_date' => $terms['pickup_date'] ?? null, 'message' => $terms['message'] ?? null,
            'expires_at' => $expires, 'lots_fingerprint' => MarketplaceDeal::fingerprint((int) $listing->id), 'created_by_user_id' => $actor->id,
        ]);
    }

    /**
     * @return array{MarketplaceNegotiation, MarketplaceListing}
     */
    private function lock(MarketplaceNegotiation $negotiation): array
    {
        $listing = MarketplaceListing::query()->whereKey($negotiation->marketplace_listing_id)->lockForUpdate()->firstOrFail();

        return [MarketplaceNegotiation::query()->whereKey($negotiation->id)->lockForUpdate()->firstOrFail(), $listing];
    }

    private function assertOpen(MarketplaceListing $listing): void
    {
        if ($listing->status !== 'published' || ($listing->closes_at !== null && $listing->closes_at->isPast())) {
            throw new BusinessRuleViolation('LISTING_NOT_OPEN', "Listing {$listing->listing_number} is not open to offers.");
        }
    }

    private function assertTurn(MarketplaceNegotiation $negotiation, string $party): void
    {
        if (! $negotiation->isOpen()) {
            throw new BusinessRuleViolation('NEGOTIATION_ENDED', 'This negotiation has already ended.');
        }
        if ($negotiation->status !== "awaiting_{$party}") {
            throw new BusinessRuleViolation('PROPOSAL_NOT_YOUR_TURN', 'The last proposal is yours: it waits for the answer of the other side.');
        }
    }

    private function assertEligible(MarketplaceListing $listing, int $buyerId): void
    {
        $hazardous = DB::table('marketplace_listing_lots as ml')->join('waste_lots as l', 'l.id', '=', 'ml.waste_lot_id')
            ->where('ml.marketplace_listing_id', $listing->id)->where('l.is_hazardous', true)->exists();
        $reason = $this->eligibility->reason($buyerId, (int) $listing->waste_type_id, $hazardous);
        if ($reason !== null) {
            throw new BusinessRuleViolation('BUYER_NOT_ELIGIBLE', 'This provider may not take this waste today ('.$reason.'): a valid accreditation and this waste among its accepted wastes are required.', ['reason' => $reason]);
        }
    }

    /**
     * Writes as the seller, who owns the listing, the negotiation and the pickup — also when the buyer acts.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function asSeller(int $sellerId, callable $callback): mixed
    {
        return $this->context->companyId() === $sellerId ? $callback() : $this->context->runAs(Company::query()->findOrFail($sellerId), $callback(...));
    }

    /**
     * Tells the other side. The seller's people on the site of the listing; the buyer's people who follow the bourse.
     */
    private function tell(MarketplaceNegotiation $negotiation, string $to, string $type): void
    {
        $listing = DB::table('marketplace_listings')->where('id', $negotiation->marketplace_listing_id)->first(['ulid', 'listing_number', 'title', 'site_id', 'company_id']);
        $params = ['number' => $listing->listing_number, 'title' => $listing->title];
        if ($to === 'buyer') {
            $this->notify->toCompanyPermission((int) $negotiation->provider_company_id, 'provider.marketplace.view', $type, $params, '/provider/marketplace/'.$listing->ulid);

            return;
        }
        $params['buyer'] = (string) DB::table('companies')->where('id', $negotiation->provider_company_id)->value('legal_name');
        $this->asSeller((int) $listing->company_id, fn () => $this->notify->toSitePermission((int) $listing->site_id, 'marketplace.view', $type, $params,
            '/marketplace/'.$listing->ulid.'?negotiation='.$negotiation->ulid));
    }
}
