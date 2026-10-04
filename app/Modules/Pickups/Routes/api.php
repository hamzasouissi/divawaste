<?php

use App\Modules\Common\Support\ReferenceCodes;
use App\Modules\Identity\Authorization\AccessResolver;
use App\Modules\Pickups\Actions\PickupWorkflow;
use App\Modules\Pickups\Enums\PickupStatus;
use App\Modules\Pickups\Models\Pickup;
use App\Modules\Pickups\Resources\PickupResource;
use App\Modules\Pickups\Support\PickupNotifier;
use App\Modules\Sites\Actions\EvaluateStockThresholds;
use App\Modules\Sites\Models\Site;
use App\Modules\Tenancy\Enums\CompanyType;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Rules\TenantExists;
use App\Modules\Tenancy\TenantContext;
use App\Modules\Waste\Models\WasteLot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;

Route::middleware(['auth:sanctum', 'tenant'])->group(function () {
    $allow = fn (Request $request, string $permission, ?int $siteId = null) => abort_unless($request->user()->can($permission, $siteId), 403);
    // Whoever loads the truck must see what is to be loaded: `pickups.load` alone opens the confirmed pickups of the site, nothing else.
    $view = fn (Request $request, Pickup $pickup) => abort_unless(
        $request->user()->can('pickups.view', $pickup->site_id) || $request->user()->can('provider.pickups.view')
        || ($pickup->status === PickupStatus::Confirmed && $request->user()->can('pickups.load', $pickup->site_id)), 403);
    $show = fn (Pickup $pickup) => new PickupResource($pickup->load('lines'));

    /*
     * Lists. An industrial sees the pickups of the sites it may view; a provider or transporter the ones addressed to it
     * (party scope applied by TenantScope).
     */
    Route::get('pickups', function (Request $request, AccessResolver $access, TenantContext $context) {
        $provider = $context->companyType() !== CompanyType::Industrial;
        $siteIds = $provider ? null : $access->siteIdsFor($request->user(), 'pickups.view');
        $loadOnly = $siteIds === [];
        if ($loadOnly) {
            $siteIds = $access->siteIdsFor($request->user(), 'pickups.load');
        }
        abort_unless($provider ? $request->user()->can('provider.pickups.view') : $siteIds !== [], 403);

        $input = $request->validate([
            'filter.status' => ['nullable', 'array'],
            'filter.status.*' => [Rule::enum(PickupStatus::class)],
            'filter.site' => ['nullable', 'string', 'ulid'],
            'filter.provider' => ['nullable', 'string', 'ulid'],
            'filter.from' => ['nullable', 'date'],
            'filter.to' => ['nullable', 'date', 'after_or_equal:filter.from'],
            'filter.variance_flagged' => ['nullable', 'boolean'],
            'filter.search' => ['nullable', 'string', 'max:32'],
            'sort' => ['nullable', Rule::in(['requested_date', '-requested_date', 'pickup_number', '-pickup_number'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $filter = $input['filter'] ?? [];
        $sort = $input['sort'] ?? '-requested_date';

        return PickupResource::collection(Pickup::query()->withCount('lines')->withSum('lines', 'declared_weight_kg')
            // A provider never sees drafts: they have not been sent to it.
            ->when($provider, fn ($query) => $query->where('status', '!=', PickupStatus::Draft))
            ->when($siteIds !== null, fn ($query) => $query->whereIn('site_id', $siteIds))
            ->when($loadOnly, fn ($query) => $query->where('status', PickupStatus::Confirmed))
            ->when($filter['status'] ?? null, fn ($query, $value) => $query->whereIn('status', $value))
            ->when(! $provider && ($filter['site'] ?? null), fn ($query) => $query->whereIn('site_id', Site::query()->select('id')->where('ulid', strtoupper($filter['site']))))
            ->when($filter['provider'] ?? null, fn ($query, $value) => $query->whereIn('provider_company_id', Company::query()->select('id')->where('ulid', strtoupper($value))))
            ->when($filter['from'] ?? null, fn ($query, $value) => $query->whereDate('requested_date', '>=', $value))
            ->when($filter['to'] ?? null, fn ($query, $value) => $query->whereDate('requested_date', '<=', $value))
            ->when(isset($filter['variance_flagged']), fn ($query) => $query->where('variance_flagged', (bool) $filter['variance_flagged']))
            ->when($filter['search'] ?? null, fn ($query, $value) => $query->where('pickup_number', 'like', addcslashes(strtoupper($value), '%_\\').'%'))
            ->orderBy(ltrim($sort, '-'), str_starts_with($sort, '-') ? 'desc' : 'asc')->orderByDesc('id')
            ->paginate(min((int) $request->integer('per_page', 25), 100)));
    })->name('pickups.index');

    Route::get('pickups/{pickup}', function (Request $request, Pickup $pickup) use ($view, $show) {
        $view($request, $pickup);

        return $show($pickup);
    })->name('pickups.show');

    /*
     * Industrial side.
     */
    Route::post('pickups', function (Request $request, PickupWorkflow $workflow) use ($allow, $show) {
        $input = $request->validate([
            'site' => ['required', 'string', new TenantExists(Site::class)],
            'provider' => ['required', 'string', Rule::exists('companies', 'ulid')->where('company_type', 'provider')],
            'transporter' => ['nullable', 'string', Rule::exists('companies', 'ulid')->where('company_type', 'provider')],
            'requested_date' => ['required', 'date', 'after_or_equal:today'],
            'lots' => ['required', 'array', 'min:1', 'max:500'],
            'lots.*' => ['required', 'string', new TenantExists(WasteLot::class)],
        ]);
        $siteId = (int) Site::query()->where('ulid', strtoupper($input['site']))->value('id');
        $allow($request, 'pickups.request', $siteId);
        $company = fn (?string $ulid) => $ulid === null ? null : (int) Company::query()->where('ulid', strtoupper($ulid))->value('id');

        return $show($workflow->create($siteId, (int) $company($input['provider']), $company($input['transporter'] ?? null),
            $input['requested_date'], array_map('strtoupper', $input['lots']), $request->user()))->response()->setStatusCode(201);
    })->name('pickups.store');

    Route::post('pickups/{pickup}/submit', function (Request $request, Pickup $pickup, PickupWorkflow $workflow, PickupNotifier $notifier) use ($allow, $show) {
        $allow($request, 'pickups.request', $pickup->site_id);
        $pickup = $workflow->submit($pickup, $request->user());
        $notifier->requested($pickup);

        return $show($pickup);
    })->name('pickups.submit');

    Route::post('pickups/{pickup}/cancel', function (Request $request, Pickup $pickup, PickupWorkflow $workflow, PickupNotifier $notifier) use ($allow, $show) {
        $allow($request, 'pickups.cancel', $pickup->site_id);
        $wasSent = $pickup->status !== PickupStatus::Draft;
        $pickup = $workflow->cancel($pickup, (string) $request->validate(['reason' => ['required', 'string', 'max:1000']])['reason'], $request->user());
        if ($wasSent) {
            $notifier->cancelled($pickup);
        }

        return $show($pickup);
    })->name('pickups.cancel');

    // M4-04: lots scanned at loading. Lots that are not scanned stay on site.
    Route::post('pickups/{pickup}/collect', function (Request $request, Pickup $pickup, PickupWorkflow $workflow, PickupNotifier $notifier, EvaluateStockThresholds $thresholds) use ($allow, $show) {
        $allow($request, 'pickups.load', $pickup->site_id);
        $input = $request->validate([
            'lots' => ['required', 'array', 'min:1'],
            'lots.*.lot' => ['required', 'string'],
            'lots.*.departure_weight_kg' => ['nullable', 'decimal:0,3', 'gte:0'],
        ]);
        $loaded = collect($input['lots'])->mapWithKeys(fn ($l) => [strtoupper($l['lot']) => isset($l['departure_weight_kg']) ? (string) $l['departure_weight_kg'] : null])->all();
        $pickup = $workflow->collect($pickup, $loaded, $request->user());
        $thresholds->forSite($pickup->site_id);
        $notifier->collected($pickup);

        return $show($pickup);
    })->name('pickups.collect');

    Route::post('pickups/{pickup}/acknowledge-variance', function (Request $request, Pickup $pickup, PickupWorkflow $workflow) use ($allow, $show) {
        $allow($request, 'pickups.complete', $pickup->site_id);

        return $show($workflow->acknowledgeVariance($pickup, (string) $request->validate(['comment' => ['required', 'string', 'max:1000']])['comment'], $request->user()));
    })->name('pickups.acknowledge-variance');

    // M4-05.
    Route::put('pickups/{pickup}/pricing', function (Request $request, Pickup $pickup, PickupWorkflow $workflow) use ($allow, $show) {
        $allow($request, 'pickups.price', $pickup->site_id);
        $input = $request->validate([
            'lots' => ['required', 'array', 'min:1'],
            'lots.*.lot_number' => ['required', 'string'],
            'lots.*.direction' => ['required', Rule::in(['sale', 'cost'])],
            'lots.*.pricing_mode' => ['required', Rule::in(['per_kg', 'per_tonne', 'flat'])],
            'lots.*.unit_price' => ['required', 'decimal:0,3', 'gte:0'],
        ]);
        $prices = collect($input['lots'])->mapWithKeys(fn ($l) => [$l['lot_number'] => [
            'direction' => $l['direction'], 'pricing_mode' => $l['pricing_mode'], 'unit_price' => (string) $l['unit_price'],
        ]])->all();

        return $show($workflow->price($pickup, $prices, $request->user()));
    })->name('pickups.pricing');

    Route::post('pickups/{pickup}/complete', function (Request $request, Pickup $pickup, PickupWorkflow $workflow, PickupNotifier $notifier) use ($allow, $show) {
        $allow($request, 'pickups.complete', $pickup->site_id);
        $pickup = $workflow->complete($pickup, $request->user());
        $notifier->completed($pickup);

        return $show($pickup);
    })->name('pickups.complete');

    /*
     * Provider side (party scope: the pickup is only reachable if it is addressed to the provider).
     */
    Route::post('pickups/{pickup}/confirm', function (Request $request, Pickup $pickup, PickupWorkflow $workflow, PickupNotifier $notifier) use ($allow, $show) {
        $allow($request, 'provider.pickups.respond');
        $pickup = $workflow->confirm($pickup, (string) $request->validate(['confirmed_date' => ['required', 'date', 'after_or_equal:today']])['confirmed_date'], $request->user());
        $notifier->confirmed($pickup);

        return $show($pickup);
    })->name('pickups.confirm');

    Route::post('pickups/{pickup}/refuse', function (Request $request, Pickup $pickup, PickupWorkflow $workflow, PickupNotifier $notifier) use ($allow, $show) {
        $allow($request, 'provider.pickups.respond');
        $pickup = $workflow->refuse($pickup, (string) $request->validate(['reason' => ['required', 'string', 'max:1000']])['reason'], $request->user());
        $notifier->refused($pickup);

        return $show($pickup);
    })->name('pickups.refuse');

    Route::post('pickups/{pickup}/receive', function (Request $request, Pickup $pickup, PickupWorkflow $workflow, PickupNotifier $notifier) use ($allow, $show) {
        $allow($request, 'provider.pickups.receive');
        $input = $request->validate([
            'lots' => ['required', 'array', 'min:1'],
            'lots.*.lot_number' => ['required', 'string'],
            'lots.*.received_weight_kg' => ['required', 'decimal:0,3', 'gte:0'],
        ]);
        $pickup = $workflow->receive($pickup, collect($input['lots'])->mapWithKeys(fn ($l) => [$l['lot_number'] => (string) $l['received_weight_kg']])->all(), $request->user());
        $notifier->received($pickup);

        return $show($pickup);
    })->name('pickups.receive');

    Route::post('pickups/{pickup}/treat', function (Request $request, Pickup $pickup, PickupWorkflow $workflow, ReferenceCodes $codes) use ($allow, $show) {
        $allow($request, 'provider.pickups.receive');
        $input = $request->validate([
            'lots' => ['required', 'array', 'min:1'],
            'lots.*.lot_number' => ['required', 'string'],
            'lots.*.channel' => ['required', 'string', Rule::exists('treatment_channels', 'code')->where('is_active', true)],
        ]);

        return $show($workflow->treat($pickup, collect($input['lots'])->mapWithKeys(fn ($l) => [$l['lot_number'] => $codes->id('treatment_channels', $l['channel'])])->all(), $request->user()));
    })->name('pickups.treat');
});
