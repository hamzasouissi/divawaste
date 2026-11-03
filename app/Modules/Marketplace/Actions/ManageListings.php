<?php

namespace App\Modules\Marketplace\Actions;

use App\Modules\Common\Exceptions\BusinessRuleViolation;
use App\Modules\Common\Support\NumberSequence;
use App\Modules\Identity\Models\User;
use App\Modules\Marketplace\Models\MarketplaceListing;
use App\Modules\Marketplace\Models\MarketplaceListingLot;
use App\Modules\Marketplace\Models\MarketplaceNegotiation;
use App\Modules\Marketplace\Support\MarketplaceEligibility;
use App\Modules\Notifications\Actions\NotifyUsers;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\TenantContext;
use App\Modules\Waste\Enums\LotStatus;
use App\Modules\Waste\Models\WasteLot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The seller's side of a listing (M4-06): lots of one site and one waste type are put together, described, given an
 * asking price if the seller wants one, then published to the eligible providers. From the draft on, the lots are
 * reserved: they cannot go into a pickup, nor be split or grouped, until the listing is sold, cancelled or expires.
 */
final class ManageListings
{
    public function __construct(private readonly TenantContext $context, private readonly MarketplaceEligibility $eligibility, private readonly NotifyUsers $notify) {}

    /**
     * @param  array{site_id: int, lots: list<string>, title?: string|null, description?: string|null, show_seller?: bool, asking?: array{pricing_mode: string, unit_price: string, direction: string}|null, available_from?: string|null, closes_at?: string|null}  $data
     */
    public function create(array $data, User $actor): MarketplaceListing
    {
        return DB::transaction(function () use ($data, $actor) {
            $lots = $this->lots($data['lots'], $data['site_id']);
            $first = $lots->first();
            $year = now()->format('Y');
            $listing = MarketplaceListing::query()->create([
                'listing_number' => NumberSequence::next($this->context->companyId(), 'listing', "ANN-{$year}-", $year, 4),
                'site_id' => $data['site_id'], 'waste_type_id' => $first->waste_type_id,
                'title' => trim((string) ($data['title'] ?? '')) !== '' ? trim((string) $data['title']) : $this->defaultTitle((int) $first->waste_type_id, $lots->count()),
                'description' => $data['description'] ?? null, 'show_seller' => (bool) ($data['show_seller'] ?? false),
                'currency_code' => (string) DB::table('companies')->where('id', $this->context->companyId())->value('currency_code'),
                'available_from' => $data['available_from'] ?? null, 'closes_at' => $data['closes_at'] ?? null,
                'created_by_user_id' => $actor->id,
            ] + $this->asking($data['asking'] ?? null));
            foreach ($lots as $lot) {
                MarketplaceListingLot::query()->create(['marketplace_listing_id' => $listing->id, 'waste_lot_id' => $lot->id]);
            }

            return $listing;
        });
    }

    /**
     * What the seller may change: the words, the asking price, the dates, whether it shows its name — and, as long as
     * nothing is published, the lots. Proposals already made keep their terms.
     *
     * @param  array{lots?: list<string>, title?: string|null, description?: string|null, show_seller?: bool, asking?: array{pricing_mode: string, unit_price: string, direction: string}|null, available_from?: string|null, closes_at?: string|null}  $data
     */
    public function update(MarketplaceListing $listing, array $data): MarketplaceListing
    {
        return DB::transaction(function () use ($listing, $data) {
            $listing = MarketplaceListing::query()->whereKey($listing->id)->lockForUpdate()->firstOrFail();
            if (! in_array($listing->status, MarketplaceListing::ACTIVE, true)) {
                throw new BusinessRuleViolation('LISTING_CLOSED', "Listing {$listing->listing_number} is {$listing->status}: it can no longer be changed.");
            }
            if (array_key_exists('lots', $data)) {
                if ($listing->status !== 'draft') {
                    throw new BusinessRuleViolation('LISTING_LOTS_FIXED', 'The lots of a published listing cannot change: buyers made their offers on them. Cancel it and publish another one.');
                }
                $lots = $this->lots($data['lots'], (int) $listing->site_id, (int) $listing->id);
                MarketplaceListingLot::query()->where('marketplace_listing_id', $listing->id)->delete();
                foreach ($lots as $lot) {
                    MarketplaceListingLot::query()->create(['marketplace_listing_id' => $listing->id, 'waste_lot_id' => $lot->id]);
                }
                $listing->waste_type_id = $lots->first()->waste_type_id;
            }
            $fields = array_intersect_key($data, array_flip(['description', 'show_seller', 'available_from', 'closes_at']));
            if (array_key_exists('title', $data) && trim((string) $data['title']) !== '') {
                $fields['title'] = trim((string) $data['title']);
            }
            if (array_key_exists('asking', $data)) {
                $fields += $this->asking($data['asking']);
            }
            $listing->forceFill($fields)->save();

            return $listing;
        });
    }

    /**
     * Published, the listing is seen by every provider that may bid on its waste, and they are told.
     */
    public function publish(MarketplaceListing $listing): MarketplaceListing
    {
        $listing = DB::transaction(function () use ($listing) {
            $listing = MarketplaceListing::query()->whereKey($listing->id)->lockForUpdate()->firstOrFail();
            if ($listing->status !== 'draft') {
                throw new BusinessRuleViolation('LISTING_NOT_DRAFT', "Listing {$listing->listing_number} is already {$listing->status}.");
            }
            if ($listing->closes_at !== null && $listing->closes_at->isPast()) {
                throw new BusinessRuleViolation('LISTING_CLOSING_PASSED', 'The closing date of the listing is past: set another one, or none.');
            }
            // The lots may have changed since the draft was made.
            $this->lots(MarketplaceListingLot::query()->where('marketplace_listing_id', $listing->id)
                ->join('waste_lots as l', 'l.id', '=', 'marketplace_listing_lots.waste_lot_id')->pluck('l.ulid')->all(), (int) $listing->site_id, (int) $listing->id);
            $listing->forceFill(['status' => 'published', 'published_at' => now()])->save();

            return $listing;
        });

        $hazardous = DB::table('marketplace_listing_lots as ml')->join('waste_lots as l', 'l.id', '=', 'ml.waste_lot_id')
            ->where('ml.marketplace_listing_id', $listing->id)->where('l.is_hazardous', true)->exists();
        $type = (string) DB::table('waste_types')->where('id', $listing->waste_type_id)->value('name');
        foreach ($this->eligibility->providersFor((int) $listing->waste_type_id, $hazardous) as $providerId) {
            $this->notify->toCompanyPermission($providerId, 'provider.marketplace.view', 'marketplace.listing_published',
                ['number' => $listing->listing_number, 'title' => $listing->title, 'waste_type' => $type], '/provider/marketplace/'.$listing->ulid);
        }

        return $listing;
    }

    /**
     * The seller withdraws the listing: the lots go back to stock, every buyer still negotiating is told.
     */
    public function cancel(MarketplaceListing $listing, ?string $reason): MarketplaceListing
    {
        return DB::transaction(function () use ($listing, $reason) {
            $listing = MarketplaceListing::query()->whereKey($listing->id)->lockForUpdate()->firstOrFail();
            if (! in_array($listing->status, MarketplaceListing::ACTIVE, true)) {
                throw new BusinessRuleViolation('LISTING_CLOSED', "Listing {$listing->listing_number} is {$listing->status}: it can no longer be cancelled.");
            }
            $listing->forceFill(['status' => 'cancelled', 'closed_at' => now(), 'cancellation_reason' => $reason])->save();
            self::release((int) $listing->id);
            $this->closeNegotiations($listing, 'listing_cancelled');

            return $listing;
        });
    }

    /**
     * Listings whose closing date passed without a deal: expired, lots back to stock, open negotiations closed.
     * Run by the scheduler and before every read of the bourse, so a closing date holds even between two runs.
     */
    public static function expireDue(): int
    {
        $due = DB::table('marketplace_listings')->where('status', 'published')->whereNotNull('closes_at')->where('closes_at', '<=', now())->get(['id', 'company_id']);
        foreach ($due as $row) {
            app(TenantContext::class)->runAs(Company::query()->findOrFail($row->company_id), function () use ($row) {
                DB::transaction(function () use ($row) {
                    $listing = MarketplaceListing::query()->whereKey($row->id)->lockForUpdate()->first();
                    if ($listing === null || $listing->status !== 'published') {
                        return;
                    }
                    $listing->forceFill(['status' => 'expired', 'closed_at' => now()])->save();
                    self::release((int) $listing->id);
                    app(self::class)->closeNegotiations($listing, 'listing_expired');
                });
            });
        }

        return $due->count();
    }

    /**
     * The lots of a listing are free again (a sold listing frees them for the pickup of the deal).
     */
    public static function release(int $listingId): void
    {
        DB::table('marketplace_listing_lots')->where('marketplace_listing_id', $listingId)->update(['is_active_flag' => null, 'updated_at' => now()]);
    }

    /**
     * Every negotiation still open on the listing ends, and its buyer is told why — never at what price it was sold.
     */
    public function closeNegotiations(MarketplaceListing $listing, string $reason, ?int $exceptId = null): void
    {
        $open = MarketplaceNegotiation::query()->where('marketplace_listing_id', $listing->id)->whereIn('status', MarketplaceNegotiation::OPEN)
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))->get();
        foreach ($open as $negotiation) {
            $negotiation->forceFill(['status' => 'closed', 'closed_reason' => $reason, 'ended_at' => now()])->save();
            $this->notify->toCompanyPermission((int) $negotiation->provider_company_id, 'provider.marketplace.view', 'marketplace.listing_closed',
                ['number' => $listing->listing_number, 'title' => $listing->title, 'reason' => __('notifications.marketplace.reasons.'.$reason)], '/provider/marketplace/'.$listing->ulid);
        }
    }

    /**
     * The lots of a listing: all on the site, of one waste type, still on site and free — not in a pickup, not offered
     * in another listing, not deleted.
     *
     * @param  list<string>  $ulids
     * @return Collection<int, WasteLot>
     */
    private function lots(array $ulids, int $siteId, ?int $listingId = null): Collection
    {
        $ulids = array_values(array_unique(array_map('strtoupper', $ulids)));
        $lots = WasteLot::query()->whereIn('ulid', $ulids)->orderBy('id')->lockForUpdate()->get();
        if ($lots->isEmpty() || $lots->count() !== count($ulids)) {
            throw new BusinessRuleViolation('LISTING_LOTS_INVALID', 'Every lot of a listing must exist.');
        }
        if ($lots->contains(fn (WasteLot $lot) => (int) $lot->site_id !== $siteId)) {
            throw new BusinessRuleViolation('LISTING_LOTS_INVALID', 'The lots of a listing are on its site: a buyer collects them in one place.');
        }
        if ($lots->pluck('waste_type_id')->unique()->count() > 1) {
            throw new BusinessRuleViolation('LISTING_MIXED_WASTE', 'A listing offers one waste type: publish one listing per type.');
        }
        foreach ($lots as $lot) {
            if (! in_array($lot->status, [LotStatus::Created, LotStatus::Stored], true)) {
                throw new BusinessRuleViolation('LOT_NOT_AVAILABLE', "Lot {$lot->lot_number} is not in stock any more: it cannot be offered.");
            }
            if (DB::table('pickup_lots')->where('waste_lot_id', $lot->id)->where('is_active_flag', true)->exists()) {
                throw new BusinessRuleViolation('LOT_IN_PICKUP', "Lot {$lot->lot_number} is already in an active pickup.");
            }
            $other = DB::table('marketplace_listing_lots as ml')->join('marketplace_listings as l', 'l.id', '=', 'ml.marketplace_listing_id')
                ->where('ml.waste_lot_id', $lot->id)->where('ml.is_active_flag', true)->when($listingId !== null, fn ($query) => $query->where('l.id', '!=', $listingId))
                ->value('l.listing_number');
            if ($other !== null) {
                throw new BusinessRuleViolation('LOT_LISTED', "Lot {$lot->lot_number} is already offered in listing {$other}.", ['listing' => $other, 'lot' => $lot->lot_number]);
            }
        }

        return $lots;
    }

    /**
     * @param  array{pricing_mode: string, unit_price: string, direction: string}|null  $asking
     * @return array{asking_pricing_mode: string|null, asking_unit_price: string|null, asking_direction: string|null}
     */
    private function asking(?array $asking): array
    {
        return ['asking_pricing_mode' => $asking['pricing_mode'] ?? null, 'asking_unit_price' => $asking['unit_price'] ?? null, 'asking_direction' => $asking['direction'] ?? null];
    }

    private function defaultTitle(int $wasteTypeId, int $count): string
    {
        $name = (string) DB::table('waste_types')->where('id', $wasteTypeId)->value('name');

        return $count === 1 ? $name : "{$name} — {$count} lots";
    }

    /**
     * When the lots can be collected at the earliest: the date the seller set, else tomorrow.
     */
    public static function earliestPickup(MarketplaceListing $listing): Carbon
    {
        $tomorrow = Carbon::tomorrow();

        return $listing->available_from !== null && $listing->available_from->isAfter($tomorrow) ? $listing->available_from->copy() : $tomorrow;
    }
}
