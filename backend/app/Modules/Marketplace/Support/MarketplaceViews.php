<?php

namespace App\Modules\Marketplace\Support;

use App\Modules\Marketplace\Models\MarketplaceNegotiation;
use App\Modules\Marketplace\Models\MarketplaceProposal;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * What each side reads of the bourse.
 *
 * A buyer reads the published listings it may bid on (and those it negotiated, to keep their history), without the
 * name of the seller until a deal with it is accepted — unless the seller chose to show it — and never anything of the
 * other buyers. The seller reads its listings with every negotiation side by side. Listings are read without tenant
 * scope on purpose for the buyer: they belong to the seller.
 */
final class MarketplaceViews
{
    private const LISTING_COLUMNS = ['ml.id', 'ml.ulid', 'ml.company_id', 'ml.site_id', 'ml.waste_type_id', 'ml.listing_number', 'ml.title', 'ml.description', 'ml.status',
        'ml.show_seller', 'ml.asking_pricing_mode', 'ml.asking_unit_price', 'ml.asking_direction', 'ml.currency_code', 'ml.available_from', 'ml.closes_at',
        'ml.published_at', 'ml.closed_at', 'ml.cancellation_reason', 'ml.accepted_negotiation_id', 'ml.pickup_id'];

    public function __construct(private readonly MarketplaceEligibility $eligibility) {}

    /**
     * The listings a buyer may read, newest first.
     *
     * @param  array{search?: string|null, family?: string|null, hazardous?: bool|null, mine?: bool}  $filter
     * @return Collection<int, array<string, mixed>>
     */
    public function catalog(int $providerId, array $filter = []): Collection
    {
        $scope = $this->eligibility->scopeOf($providerId);
        $rows = $this->visible($providerId, $scope)
            ->when($filter['search'] ?? null, fn ($query, $value) => $query->where(fn ($q) => $q->where('ml.title', 'like', '%'.addcslashes($value, '%_\\').'%')
                ->orWhere('ml.listing_number', 'like', addcslashes(strtoupper($value), '%_\\').'%')))
            ->when($filter['family'] ?? null, fn ($query, $value) => $query->whereIn('ml.waste_type_id', DB::table('waste_types as t')->join('waste_families as f', 'f.id', '=', 't.waste_family_id')->where('f.code', $value)->select('t.id')))
            ->when(isset($filter['hazardous']), fn ($query) => $filter['hazardous']
                ? $query->whereExists($this->hazardousLot()) : $query->whereNotExists($this->hazardousLot()))
            ->when($filter['mine'] ?? false, fn ($query) => $query->whereExists(fn ($q) => $q->from('marketplace_negotiations as n')->whereColumn('n.marketplace_listing_id', 'ml.id')->where('n.provider_company_id', $providerId)))
            ->orderByRaw("ml.status = 'published' desc")->orderByDesc('ml.published_at')->orderByDesc('ml.id')->limit(200)->get(self::LISTING_COLUMNS);

        return $rows->map(fn (stdClass $row) => $this->forBuyer($row, $providerId, false));
    }

    /**
     * One listing as a buyer reads it, or null when it may not read it.
     *
     * @return array<string, mixed>|null
     */
    public function listingForBuyer(int $providerId, string $ulid): ?array
    {
        $row = $this->visible($providerId, $this->eligibility->scopeOf($providerId))->where('ml.ulid', strtoupper($ulid))->first(self::LISTING_COLUMNS);

        return $row === null ? null : $this->forBuyer($row, $providerId, true);
    }

    /**
     * The id of a listing a buyer may bid on now (published, open, its waste among what it may take), or null.
     */
    public function biddable(int $providerId, string $ulid): ?int
    {
        $scope = $this->eligibility->scopeOf($providerId);
        if ($scope === null) {
            return null;
        }
        $id = $this->open($this->inScope(DB::table('marketplace_listings as ml'), $scope))->where('ml.ulid', strtoupper($ulid))->value('ml.id');

        return $id === null ? null : (int) $id;
    }

    /**
     * The seller's own listing, with its lots and every negotiation.
     *
     * @return array<string, mixed>
     */
    public function listingForSeller(int $listingId, bool $detail = true): array
    {
        $row = DB::table('marketplace_listings as ml')->where('ml.id', $listingId)->first(self::LISTING_COLUMNS);
        $lots = MarketplaceDeal::lots($listingId);
        $totals = MarketplaceDeal::totals($lots);
        $fingerprint = MarketplaceDeal::fingerprint($listingId);
        $negotiations = MarketplaceNegotiation::query()->where('marketplace_listing_id', $listingId)->orderByDesc('updated_at')->get();
        $last = $this->lastProposals($negotiations->pluck('id')->all());
        $buyers = DB::table('companies')->whereIn('id', $negotiations->pluck('provider_company_id'))->get(['id', 'ulid', 'legal_name', 'city'])->keyBy('id');

        return $this->common($row, $lots, $totals, $detail) + [
            'site' => DB::table('sites')->where('id', $row->site_id)->first(['ulid', 'code', 'name']),
            'pickup' => $row->pickup_id === null ? null : DB::table('pickups')->where('id', $row->pickup_id)->first(['ulid', 'pickup_number', 'status']),
            'cancellation_reason' => $row->cancellation_reason,
            'negotiations_count' => $negotiations->count(),
            'awaiting_seller' => $negotiations->where('status', 'awaiting_seller')->count(),
            'negotiations' => $negotiations->map(fn (MarketplaceNegotiation $negotiation) => [
                'ulid' => $negotiation->ulid,
                'status' => $negotiation->status,
                'buyer' => ($buyer = $buyers[$negotiation->provider_company_id] ?? null) === null ? null : ['ulid' => $buyer->ulid, 'legal_name' => $buyer->legal_name, 'city' => $buyer->city],
                'last_proposal' => ($proposal = $last[$negotiation->id] ?? null) === null ? null : $this->proposal($proposal, $fingerprint, $totals),
                'updated_at' => $negotiation->updated_at?->toIso8601ZuluString(),
            ])->values()->all(),
        ];
    }

    /**
     * A negotiation, proposal by proposal, as the side reading it sees it.
     *
     * @param  'seller'|'buyer'  $party
     * @return array<string, mixed>
     */
    public function negotiation(MarketplaceNegotiation $negotiation, string $party): array
    {
        $listing = DB::table('marketplace_listings as ml')->where('ml.id', $negotiation->marketplace_listing_id)->first(self::LISTING_COLUMNS);
        $lots = MarketplaceDeal::lots((int) $listing->id);
        $totals = MarketplaceDeal::totals($lots);
        $fingerprint = MarketplaceDeal::fingerprint((int) $listing->id);
        $proposals = MarketplaceProposal::query()->where('marketplace_negotiation_id', $negotiation->id)->orderBy('sequence')->get();
        $buyer = DB::table('companies')->where('id', $negotiation->provider_company_id)->first(['ulid', 'legal_name', 'city']);
        $pickup = $negotiation->status === 'accepted' && $listing->pickup_id !== null ? DB::table('pickups')->where('id', $listing->pickup_id)->first(['ulid', 'pickup_number', 'status']) : null;

        return [
            'ulid' => $negotiation->ulid,
            'status' => $negotiation->status,
            // The side the reader is on, and whether the last proposal waits for its answer.
            'party' => $party,
            'your_turn' => $negotiation->status === "awaiting_{$party}",
            'refused_by' => $negotiation->refused_by,
            'closed_reason' => $negotiation->closed_reason,
            'end_message' => $negotiation->end_message,
            'listing' => $party === 'seller' ? $this->common($listing, $lots, $totals, true) + ['site' => DB::table('sites')->where('id', $listing->site_id)->first(['ulid', 'code', 'name'])]
                : $this->forBuyer($listing, (int) $negotiation->provider_company_id, true),
            'buyer' => $buyer === null ? null : ['ulid' => $buyer->ulid, 'legal_name' => $buyer->legal_name, 'city' => $buyer->city],
            'proposals' => $proposals->map(fn (MarketplaceProposal $proposal) => $this->proposal($proposal, $fingerprint, $totals))->values()->all(),
            'accepted_proposal' => $negotiation->accepted_proposal_id === null ? null : $proposals->firstWhere('id', $negotiation->accepted_proposal_id)?->ulid,
            'commission_pct' => $negotiation->commission_pct,
            'accepted_at' => $negotiation->accepted_at?->toIso8601ZuluString(),
            'ended_at' => $negotiation->ended_at?->toIso8601ZuluString(),
            'pickup' => $pickup,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $lots
     * @param  array{weight_kg: string|null, unweighed_lots: int, quantities: array<string, string>, lots_count: int}  $totals
     * @return array<string, mixed>
     */
    private function common(stdClass $row, Collection $lots, array $totals, bool $detail): array
    {
        $type = DB::table('waste_types as t')->leftJoin('waste_families as f', 'f.id', '=', 't.waste_family_id')->leftJoin('waste_catalog_items as i', 'i.id', '=', 't.waste_catalog_item_id')
            ->where('t.id', $row->waste_type_id)->first(['t.name', 'f.code as family', 'i.code as catalog_item']);

        return [
            'ulid' => $row->ulid,
            'listing_number' => $row->listing_number,
            'title' => $row->title,
            'description' => $row->description,
            'status' => $row->status,
            'waste_type' => ['name' => $type?->name, 'family' => $type?->family, 'catalog_item' => $type?->catalog_item],
            'is_hazardous' => $lots->contains('is_hazardous', true),
            'show_seller' => (bool) $row->show_seller,
            'asking_price' => $row->asking_pricing_mode === null ? null : ['pricing_mode' => $row->asking_pricing_mode, 'unit_price' => (string) $row->asking_unit_price,
                'direction' => $row->asking_direction, 'amount' => MarketplaceDeal::amount($row->asking_pricing_mode, (string) $row->asking_unit_price, $row->asking_direction, $totals['weight_kg'])],
            'currency_code' => $row->currency_code,
            // Known weight only; lots nobody weighed are counted apart, quantities of counted waste per unit.
            'totals' => $totals,
            // The average of the lots weighted by weight, when every lot is weighed and composed; else null.
            'composition' => MarketplaceDeal::composition($lots),
            'available_from' => $row->available_from,
            'closes_at' => $row->closes_at === null ? null : now()->parse($row->closes_at, 'UTC')->toIso8601ZuluString(),
            'published_at' => $row->published_at === null ? null : now()->parse($row->published_at, 'UTC')->toIso8601ZuluString(),
            'closed_at' => $row->closed_at === null ? null : now()->parse($row->closed_at, 'UTC')->toIso8601ZuluString(),
            'lots' => $detail ? $lots->all() : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function forBuyer(stdClass $row, int $providerId, bool $detail): array
    {
        $lots = MarketplaceDeal::lots((int) $row->id);
        $totals = MarketplaceDeal::totals($lots);
        $mine = MarketplaceNegotiation::query()->where('marketplace_listing_id', $row->id)->where('provider_company_id', $providerId)->first();
        $won = $mine !== null && $mine->status === 'accepted';
        $site = DB::table('sites as s')->leftJoin('countries as c', 'c.id', '=', 's.country_id')->where('s.id', $row->site_id)->first(['s.city', 's.region', 'c.iso2 as country']);
        $seller = $row->show_seller || $won ? DB::table('companies')->where('id', $row->company_id)->first(['ulid', 'legal_name', 'city']) : null;
        $last = $mine === null ? null : MarketplaceProposal::query()->where('marketplace_negotiation_id', $mine->id)->orderByDesc('sequence')->first();

        return $this->common($row, $lots, $totals, $detail) + [
            // Who sells, once it may be known; until then where the lots are, in broad terms.
            'seller' => $seller === null ? null : ['ulid' => $seller->ulid, 'legal_name' => $seller->legal_name, 'city' => $seller->city],
            'location' => ['region' => $site?->region, 'city' => $seller !== null ? $site?->city : null, 'country' => $site?->country],
            'my_negotiation' => $mine === null ? null : ['ulid' => $mine->ulid, 'status' => $mine->status, 'your_turn' => $mine->status === 'awaiting_buyer',
                'last_proposal' => $last === null ? null : $this->proposal($last, MarketplaceDeal::fingerprint((int) $row->id), $totals)],
            'can_offer' => $row->status === 'published' && ($row->closes_at === null || now()->parse($row->closes_at, 'UTC')->isFuture()) && ($mine === null || ! $mine->isOpen()),
            // Only the winner learns whether a pickup was made; nobody else learns anything of the deal.
            'pickup' => $won && $row->pickup_id !== null ? DB::table('pickups')->where('id', $row->pickup_id)->first(['ulid', 'pickup_number', 'status']) : null,
        ];
    }

    /**
     * @param  array{weight_kg: string|null, unweighed_lots: int, quantities: array<string, string>, lots_count: int}  $totals
     * @return array<string, mixed>
     */
    private function proposal(MarketplaceProposal $proposal, string $fingerprint, array $totals): array
    {
        $amount = MarketplaceDeal::amount($proposal->pricing_mode, (string) $proposal->unit_price, $proposal->direction, $totals['weight_kg']);

        return [
            'ulid' => $proposal->ulid,
            'sequence' => $proposal->sequence,
            'party' => $proposal->party,
            'pricing_mode' => $proposal->pricing_mode,
            'unit_price' => (string) $proposal->unit_price,
            // revenue = the buyer pays the seller; cost = the seller pays the buyer to take the waste.
            'direction' => $proposal->direction,
            'currency_code' => $proposal->currency_code,
            // What it comes to on the weight known today, signed for the seller; partial when some lots are not weighed.
            'amount' => $amount,
            'amount_partial' => $amount !== null && $proposal->pricing_mode !== 'flat' && $totals['unweighed_lots'] > 0,
            'pickup_date' => $proposal->pickup_date?->toDateString(),
            'message' => $proposal->message,
            'expires_at' => $proposal->expires_at?->toIso8601ZuluString(),
            'expired' => $proposal->hasExpired(),
            // Made on lots that have changed since: it can be answered, not accepted.
            'outdated' => $proposal->lots_fingerprint !== $fingerprint,
            'created_at' => $proposal->created_at?->toIso8601ZuluString(),
        ];
    }

    /**
     * @param  list<int>  $negotiationIds
     * @return Collection<int, MarketplaceProposal>
     */
    private function lastProposals(array $negotiationIds): Collection
    {
        return MarketplaceProposal::query()->whereIn('marketplace_negotiation_id', $negotiationIds)
            ->whereIn('id', MarketplaceProposal::query()->selectRaw('MAX(id)')->whereIn('marketplace_negotiation_id', $negotiationIds)->groupBy('marketplace_negotiation_id'))
            ->get()->keyBy('marketplace_negotiation_id');
    }

    /**
     * Published and open listings of what the buyer may take, plus every listing it negotiated (its history).
     *
     * @param  array{families: list<int>, items: list<int>, hazardous: bool}|null  $scope
     */
    private function visible(int $providerId, ?array $scope): Builder
    {
        return DB::table('marketplace_listings as ml')->where(function ($query) use ($providerId, $scope) {
            $query->whereExists(fn ($q) => $q->from('marketplace_negotiations as n')->whereColumn('n.marketplace_listing_id', 'ml.id')->where('n.provider_company_id', $providerId));
            if ($scope !== null) {
                $query->orWhere(fn ($q) => $this->open($this->inScope($q, $scope)));
            }
        });
    }

    /**
     * @template TBuilder of \Illuminate\Database\Query\Builder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    private function open($query)
    {
        return $query->where('ml.status', 'published')->where(fn ($q) => $q->whereNull('ml.closes_at')->orWhere('ml.closes_at', '>', now()));
    }

    /**
     * @template TBuilder of \Illuminate\Database\Query\Builder
     *
     * @param  TBuilder  $query
     * @param  array{families: list<int>, items: list<int>, hazardous: bool}  $scope
     * @return TBuilder
     */
    private function inScope($query, array $scope)
    {
        $query->whereIn('ml.waste_type_id', DB::table('waste_types as t')->select('t.id')->where(fn ($q) => $q
            ->whereIn('t.waste_family_id', $scope['families'] === [] ? [0] : $scope['families'])
            ->orWhereIn('t.waste_catalog_item_id', $scope['items'] === [] ? [0] : $scope['items'])));
        if (! $scope['hazardous']) {
            $query->whereNotExists($this->hazardousLot());
        }

        return $query;
    }

    private function hazardousLot(): \Closure
    {
        return fn ($q) => $q->from('marketplace_listing_lots as hl')->join('waste_lots as hw', 'hw.id', '=', 'hl.waste_lot_id')
            ->whereColumn('hl.marketplace_listing_id', 'ml.id')->where('hw.is_hazardous', true);
    }
}
