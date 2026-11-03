<?php

use App\Modules\Identity\Authorization\AccessResolver;
use App\Modules\Marketplace\Actions\ManageListings;
use App\Modules\Marketplace\Actions\Negotiate;
use App\Modules\Marketplace\Models\MarketplaceListing;
use App\Modules\Marketplace\Models\MarketplaceNegotiation;
use App\Modules\Marketplace\Support\MarketplaceViews;
use App\Modules\Sites\Models\Site;
use App\Modules\Tenancy\Enums\CompanyType;
use App\Modules\Tenancy\Rules\TenantExists;
use App\Modules\Tenancy\TenantContext;
use App\Modules\Waste\Models\WasteLot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;

/*
 * The bourse (M4-06). A company offers lots it produced; the eligible providers of the platform propose a price, and
 * each negotiates in private with the seller until a proposal is accepted or refused. The accepted deal becomes a
 * pickup to that buyer at the agreed price.
 */
Route::middleware(['auth:sanctum', 'tenant'])->group(function () {
    // The terms of a proposal, or of an asking price: a price by kilogram, by tonne, or for the whole; who pays whom.
    $terms = fn (string $prefix = '') => [
        "{$prefix}pricing_mode" => ['required', Rule::in(['per_kg', 'per_tonne', 'flat'])],
        "{$prefix}unit_price" => ['required', 'decimal:0,3', 'gte:0', 'lte:999999999'],
        // revenue = the buyer pays the seller; cost = the seller pays the buyer to take the waste.
        "{$prefix}direction" => ['required', Rule::in(['revenue', 'cost'])],
    ];
    $proposal = fn () => $terms() + [
        'pickup_date' => ['nullable', 'date', 'after_or_equal:today'],
        'message' => ['nullable', 'string', 'max:1000'],
        'expires_at' => ['nullable', 'date', 'after_or_equal:today'],
    ];
    $listingFields = fn () => [
        'title' => ['nullable', 'string', 'max:191'],
        'description' => ['nullable', 'string', 'max:5000'],
        'show_seller' => ['sometimes', 'boolean'],
        'asking_price' => ['nullable', 'array'],
        ...array_map(fn (array $rules) => ['required_with:asking_price', ...array_slice($rules, 1)], $terms('asking_price.')),
        'available_from' => ['nullable', 'date'],
        'closes_at' => ['nullable', 'date', 'after:now'],
    ];
    // Only what the request carries: a field left out of an update keeps its value.
    $listingData = fn (array $input) => array_intersect_key([
        'title' => $input['title'] ?? null, 'description' => $input['description'] ?? null, 'show_seller' => (bool) ($input['show_seller'] ?? false),
        'asking' => isset($input['asking_price']) ? ['pricing_mode' => $input['asking_price']['pricing_mode'], 'unit_price' => (string) $input['asking_price']['unit_price'],
            'direction' => $input['asking_price']['direction']] : null,
        'available_from' => $input['available_from'] ?? null, 'closes_at' => $input['closes_at'] ?? null,
    ], array_flip(array_map(fn (string $key) => $key === 'asking_price' ? 'asking' : $key, array_keys($input))));
    $industrial = fn () => abort_unless(app(TenantContext::class)->companyType() === CompanyType::Industrial, 404);
    $provider = fn () => abort_if(app(TenantContext::class)->companyType() === CompanyType::Industrial, 404);
    // A closing date holds even between two runs of the scheduler.
    $expire = fn () => ManageListings::expireDue();

    /*
     * Seller side: the listings of the company, on the sites it may see.
     */
    Route::get('marketplace/listings', function (Request $request, AccessResolver $access, MarketplaceViews $views) use ($industrial, $expire) {
        $industrial();
        $expire();
        $siteIds = $access->siteIdsFor($request->user(), 'marketplace.view');
        abort_if($siteIds === [], 403);
        $input = $request->validate([
            'filter.status' => ['nullable', 'array'], 'filter.status.*' => [Rule::in(['draft', 'published', 'sold', 'expired', 'cancelled'])],
            'filter.site' => ['nullable', 'string', 'ulid'],
        ]);
        $filter = $input['filter'] ?? [];
        $ids = MarketplaceListing::query()->when($siteIds !== null, fn ($query) => $query->whereIn('site_id', $siteIds))
            ->when($filter['status'] ?? null, fn ($query, $value) => $query->whereIn('status', $value))
            ->when($filter['site'] ?? null, fn ($query, $value) => $query->whereIn('site_id', Site::query()->select('id')->where('ulid', strtoupper($value))))
            ->orderByRaw("FIELD(status, 'published', 'draft', 'sold', 'expired', 'cancelled')")->orderByDesc('id')->limit(200)->pluck('id');

        return ['data' => $ids->map(fn ($id) => $views->listingForSeller((int) $id, false))->values()];
    })->name('marketplace.listings.index');

    // A draft: lots of one site and one waste type, still in stock and free. Published separately.
    Route::post('marketplace/listings', function (Request $request, ManageListings $listings, MarketplaceViews $views) use ($industrial, $listingFields, $listingData) {
        $industrial();
        $input = $request->validate([
            'site' => ['required', 'string', new TenantExists(Site::class)],
            'lots' => ['required', 'array', 'min:1', 'max:200'],
            'lots.*' => ['required', 'string', new TenantExists(WasteLot::class)],
        ] + $listingFields());
        $site = Site::query()->where('ulid', strtoupper($input['site']))->firstOrFail(['id']);
        abort_unless($request->user()->can('marketplace.manage', $site), 403);
        $listing = $listings->create(['site_id' => (int) $site->id, 'lots' => $input['lots']] + $listingData($input), $request->user());

        return response()->json(['data' => $views->listingForSeller((int) $listing->id)], 201);
    })->name('marketplace.listings.store');

    Route::get('marketplace/listings/{listing}', function (Request $request, MarketplaceListing $listing, MarketplaceViews $views) use ($expire) {
        abort_unless($request->user()->can('marketplace.view', $listing->site_id), 403);
        $expire();

        return ['data' => $views->listingForSeller((int) $listing->id)];
    })->name('marketplace.listings.show');

    // The words, the asking price, the dates, the name shown or not; the lots only while it is a draft.
    Route::put('marketplace/listings/{listing}', function (Request $request, MarketplaceListing $listing, ManageListings $listings, MarketplaceViews $views) use ($listingFields, $listingData) {
        abort_unless($request->user()->can('marketplace.manage', $listing->site_id), 403);
        $input = $request->validate([
            'lots' => ['sometimes', 'array', 'min:1', 'max:200'],
            'lots.*' => ['required', 'string', new TenantExists(WasteLot::class)],
        ] + $listingFields());
        $data = $listingData($input) + (isset($input['lots']) ? ['lots' => $input['lots']] : []);
        $listings->update($listing, $data);

        return ['data' => $views->listingForSeller((int) $listing->id)];
    })->name('marketplace.listings.update');

    Route::post('marketplace/listings/{listing}/publish', function (Request $request, MarketplaceListing $listing, ManageListings $listings, MarketplaceViews $views) {
        abort_unless($request->user()->can('marketplace.manage', $listing->site_id), 403);
        $listings->publish($listing);

        return ['data' => $views->listingForSeller((int) $listing->id)];
    })->name('marketplace.listings.publish');

    // Withdrawn: the lots go back to stock and every buyer still negotiating is told.
    Route::post('marketplace/listings/{listing}/cancel', function (Request $request, MarketplaceListing $listing, ManageListings $listings, MarketplaceViews $views) {
        abort_unless($request->user()->can('marketplace.manage', $listing->site_id), 403);
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:500']])['reason'] ?? null;
        $listings->cancel($listing, $reason);

        return ['data' => $views->listingForSeller((int) $listing->id)];
    })->name('marketplace.listings.cancel');

    /*
     * Buyer side: the published listings the provider may bid on, and the ones it negotiated.
     */
    Route::get('marketplace/catalog', function (Request $request, TenantContext $context, MarketplaceViews $views) use ($provider, $expire) {
        $provider();
        abort_unless($request->user()->can('provider.marketplace.view'), 403);
        $expire();
        $input = $request->validate([
            'filter.search' => ['nullable', 'string', 'max:100'], 'filter.family' => ['nullable', 'string', 'max:40'],
            'filter.hazardous' => ['nullable', 'boolean'], 'filter.mine' => ['nullable', 'boolean'],
        ]);
        $filter = $input['filter'] ?? [];
        if (isset($filter['hazardous'])) {
            $filter['hazardous'] = filter_var($filter['hazardous'], FILTER_VALIDATE_BOOLEAN);
        }
        $filter['mine'] = filter_var($filter['mine'] ?? false, FILTER_VALIDATE_BOOLEAN);

        return ['data' => $views->catalog($context->companyId(), $filter)];
    })->name('marketplace.catalog.index');

    Route::get('marketplace/catalog/{listing}', function (Request $request, string $listing, TenantContext $context, MarketplaceViews $views) use ($provider, $expire) {
        $provider();
        abort_unless($request->user()->can('provider.marketplace.view'), 403);
        $expire();

        return ['data' => $views->listingForBuyer($context->companyId(), $listing) ?? abort(404)];
    })->name('marketplace.catalog.show');

    // The buyer's first offer, or a new one after its earlier offer was refused or withdrawn.
    Route::post('marketplace/catalog/{listing}/offers', function (Request $request, string $listing, TenantContext $context, Negotiate $negotiate, MarketplaceViews $views) use ($provider, $proposal) {
        $provider();
        abort_unless($request->user()->can('provider.marketplace.offer'), 403);
        ManageListings::expireDue();
        $id = $views->biddable($context->companyId(), $listing) ?? abort(404);
        $negotiation = $negotiate->offer($id, $request->validate($proposal()), $request->user());

        return response()->json(['data' => $views->negotiation($negotiation->refresh(), 'buyer')], 201);
    })->name('marketplace.catalog.offer');

    /*
     * A negotiation, from either side. The seller sees every negotiation of its listings; a buyer only its own.
     */
    $side = function (Request $request, MarketplaceNegotiation $negotiation, Negotiate $negotiate, string $action): string {
        $party = $negotiate->party($negotiation);
        $siteId = (int) DB::table('marketplace_listings')->where('id', $negotiation->marketplace_listing_id)->value('site_id');
        abort_unless($party === 'seller'
            ? $request->user()->can(match ($action) {
                'view' => 'marketplace.view', 'decide' => 'marketplace.accept', default => 'marketplace.manage'
            }, $siteId)
            : $request->user()->can($action === 'view' ? 'provider.marketplace.view' : 'provider.marketplace.offer'), 403);

        return $party;
    };

    Route::get('marketplace/negotiations/{negotiation}', function (Request $request, MarketplaceNegotiation $negotiation, Negotiate $negotiate, MarketplaceViews $views) use ($side) {
        ManageListings::expireDue();

        return ['data' => $views->negotiation($negotiation->refresh(), $side($request, $negotiation, $negotiate, 'view'))];
    })->name('marketplace.negotiations.show');

    // Another proposal, from the side whose turn it is.
    Route::post('marketplace/negotiations/{negotiation}/counter', function (Request $request, MarketplaceNegotiation $negotiation, Negotiate $negotiate, MarketplaceViews $views) use ($side, $proposal) {
        $party = $side($request, $negotiation, $negotiate, 'counter');
        $negotiate->counter($negotiation, $request->validate($proposal()), $request->user());

        return ['data' => $views->negotiation($negotiation->refresh(), $party)];
    })->name('marketplace.negotiations.counter');

    // Accepts the other side's last proposal, named so that what is accepted is what was read.
    Route::post('marketplace/negotiations/{negotiation}/accept', function (Request $request, MarketplaceNegotiation $negotiation, Negotiate $negotiate, MarketplaceViews $views) use ($side) {
        $party = $side($request, $negotiation, $negotiate, 'decide');
        $proposalUlid = (string) $request->validate(['proposal' => ['required', 'string', 'ulid']])['proposal'];
        $negotiate->accept($negotiation, $proposalUlid, $request->user());

        return ['data' => $views->negotiation($negotiation->refresh(), $party)];
    })->name('marketplace.negotiations.accept');

    Route::post('marketplace/negotiations/{negotiation}/refuse', function (Request $request, MarketplaceNegotiation $negotiation, Negotiate $negotiate, MarketplaceViews $views) use ($side) {
        $party = $side($request, $negotiation, $negotiate, 'decide');
        $negotiate->refuse($negotiation, $request->validate(['message' => ['nullable', 'string', 'max:1000']])['message'] ?? null, $request->user());

        return ['data' => $views->negotiation($negotiation->refresh(), $party)];
    })->name('marketplace.negotiations.refuse');

    // The buyer ends its negotiation: it takes back its offer.
    Route::post('marketplace/negotiations/{negotiation}/withdraw', function (Request $request, MarketplaceNegotiation $negotiation, Negotiate $negotiate, MarketplaceViews $views) use ($side) {
        $party = $side($request, $negotiation, $negotiate, 'counter');
        $negotiate->withdraw($negotiation, $request->validate(['message' => ['nullable', 'string', 'max:1000']])['message'] ?? null, $request->user());

        return ['data' => $views->negotiation($negotiation->refresh(), $party)];
    })->name('marketplace.negotiations.withdraw');
});
