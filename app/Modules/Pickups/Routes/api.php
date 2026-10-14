<?php

use App\Modules\Common\Exceptions\BusinessRuleViolation;
use App\Modules\Common\Support\ReferenceCodes;
use App\Modules\Documents\Actions\ManifestWorkflow;
use App\Modules\Identity\Authorization\AccessResolver;
use App\Modules\Pickups\Actions\PickupWorkflow;
use App\Modules\Pickups\Enums\PickupStatus;
use App\Modules\Pickups\Models\Pickup;
use App\Modules\Pickups\Resources\PickupResource;
use App\Modules\Pickups\Support\PickupCompliance;
use App\Modules\Pickups\Support\PickupNotifier;
use App\Modules\Pickups\Support\ProviderRequirements;
use App\Modules\Providers\Models\ExternalPartner;
use App\Modules\Sites\Actions\EvaluateStockThresholds;
use App\Modules\Sites\Models\Site;
use App\Modules\Tenancy\Enums\CompanyType;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Rules\TenantExists;
use App\Modules\Tenancy\TenantContext;
use App\Modules\Waste\Models\WasteLot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;

Route::middleware(['auth:sanctum', 'tenant'])->group(function () {
    $allow = fn (Request $request, string $permission, ?int $siteId = null) => abort_unless($request->user()->can($permission, $siteId), 403);
    // Whoever loads the truck must see what is to be loaded: `pickups.load` alone opens the confirmed pickups of the site, nothing else.
    $view = fn (Request $request, Pickup $pickup) => abort_unless(
        $request->user()->can('pickups.view', $pickup->site_id) || $request->user()->can('provider.pickups.view')
        || ($pickup->status === PickupStatus::Confirmed && $request->user()->can('pickups.load', $pickup->site_id)), 403);
    $show = fn (Pickup $pickup) => new PickupResource($pickup->load('lines'));
    // A stage that belongs to the receiver. A receiver with an account does it itself; for a partner without an
    // account, the industrial that owns the pickup records it. Returns true when the industrial acts on its behalf.
    $receiverStage = function (Request $request, Pickup $pickup, string $providerPermission, string $ownerPermission): bool {
        $owner = app(TenantContext::class)->companyId() === (int) $pickup->company_id;
        abort_unless($owner
            ? $pickup->external_provider_id !== null && $request->user()->can($ownerPermission, $pickup->site_id)
            : $request->user()->can($providerPermission), 403);

        return $owner;
    };
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
            'filter.documents_outstanding' => ['nullable', 'boolean'],
            'filter.search' => ['nullable', 'string', 'max:32'],
            'sort' => ['nullable', Rule::in(['requested_date', '-requested_date', 'pickup_number', '-pickup_number'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $filter = $input['filter'] ?? [];
        $sort = $input['sort'] ?? '-requested_date';

        return PickupResource::collection(PickupCompliance::withState(Pickup::query()->withSum('lines', 'declared_weight_kg'))
            // Beside each total, the lots it leaves out because nobody weighed them at that stage.
            ->withCount(['lines', 'lines as departure_unweighed_lots' => fn ($query) => $query->whereNotNull('loaded_at')->whereNull('departure_weight_kg'),
                'lines as received_unweighed_lots' => fn ($query) => $query->whereNotNull('received_at')->whereNull('received_weight_kg')])
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
            // Pickups that left and whose slips are not all signed, or that carry a lot with no slip — closed or not.
            ->when($filter['documents_outstanding'] ?? false, fn ($query) => PickupCompliance::whereOutstanding($query))
            ->when($filter['search'] ?? null, fn ($query, $value) => $query->where('pickup_number', 'like', addcslashes(strtoupper($value), '%_\\').'%'))
            ->orderBy(ltrim($sort, '-'), str_starts_with($sort, '-') ? 'desc' : 'asc')->orderByDesc('id')
            ->paginate(min((int) $request->integer('per_page', 25), 100)));
    })->name('pickups.index');

    // Gaps at reception, per waste type, over a period: where measures keep disagreeing with what was declared.
    // Only the receptions judged by a rule are counted as such; older ones carry no outcome and are reported apart.
    Route::get('pickups/tolerance-summary', function (Request $request, AccessResolver $access) {
        $siteIds = $access->siteIdsFor($request->user(), 'pickups.view');
        abort_if($siteIds === [], 403);
        $input = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $rows = DB::table('pickup_lots as pl')->join('pickups as p', 'p.id', '=', 'pl.pickup_id')
            ->join('waste_lots as l', 'l.id', '=', 'pl.waste_lot_id')->join('waste_types as t', 't.id', '=', 'l.waste_type_id')
            ->where('pl.company_id', app(TenantContext::class)->companyId())->whereNotNull('pl.received_at')
            ->when($siteIds !== null, fn ($query) => $query->whereIn('p.site_id', $siteIds))
            ->when($input['from'] ?? null, fn ($query, $value) => $query->whereDate('pl.received_at', '>=', $value))
            ->when($input['to'] ?? null, fn ($query, $value) => $query->whereDate('pl.received_at', '<=', $value))
            ->groupBy('t.ulid', 't.code', 't.name', 'pl.unit_code')->orderBy('t.code')
            ->get(['t.ulid', 't.code', 't.name', 'pl.unit_code as unit', DB::raw('COUNT(*) as received'),
                DB::raw("SUM(pl.tolerance_status = 'within') as within"), DB::raw("SUM(pl.tolerance_status = 'warning') as warning"),
                DB::raw("SUM(pl.tolerance_status = 'blocking') as blocking"), DB::raw("SUM(pl.tolerance_status = 'no_reference') as no_reference"),
                DB::raw('SUM(pl.tolerance_status IS NULL) as not_judged')]);
        $count = fn (string $key) => (int) $rows->sum($key);

        return ['data' => $rows->map(fn ($row) => [
            'waste_type' => ['ulid' => $row->ulid, 'code' => $row->code, 'name' => $row->name], 'unit' => $row->unit ?? 'kg',
            'received' => (int) $row->received, 'within' => (int) $row->within, 'warning' => (int) $row->warning, 'blocking' => (int) $row->blocking,
            'no_reference' => (int) $row->no_reference, 'not_judged' => (int) $row->not_judged,
        ]), 'meta' => ['received' => $count('received'), 'within' => $count('within'), 'warning' => $count('warning'), 'blocking' => $count('blocking'),
            'no_reference' => $count('no_reference'), 'not_judged' => $count('not_judged')]];
    })->name('pickups.tolerance-summary');

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
            // The receiver: a provider of the platform, or a partner without an account. Exactly one of the two.
            'provider' => ['required_without:external_provider', 'prohibits:external_provider', 'nullable', 'string', Rule::exists('companies', 'ulid')->where('company_type', 'provider')],
            'external_provider' => ['nullable', 'string', new TenantExists(ExternalPartner::class, fn ($q) => $q->where('is_active', true)->whereNull('linked_company_id'))],
            // The transporter, when it is not the receiver itself.
            'transporter' => ['nullable', 'prohibits:external_transporter', 'string', Rule::exists('companies', 'ulid')->where('company_type', 'provider')],
            'external_transporter' => ['nullable', 'string', new TenantExists(ExternalPartner::class, fn ($q) => $q->where('is_active', true)->whereNull('linked_company_id'))],
            'requested_date' => ['required', 'date', 'after_or_equal:today'],
            'lots' => ['required', 'array', 'min:1', 'max:500'],
            'lots.*' => ['required', 'string', new TenantExists(WasteLot::class)],
            // What the receiver is to do with the waste, when it differs from what each waste type plans. It selects the tolerance.
            'treatment_channel' => ['nullable', 'string', Rule::exists('treatment_channels', 'code')->where('is_active', true)],
        ]);
        $siteId = (int) Site::query()->where('ulid', strtoupper($input['site']))->value('id');
        $allow($request, 'pickups.request', $siteId);
        $company = fn (?string $ulid) => $ulid === null ? null : (int) Company::query()->where('ulid', strtoupper($ulid))->value('id');

        $partner = fn (?string $ulid) => $ulid === null ? null : (int) ExternalPartner::query()->where('ulid', strtoupper($ulid))->value('id');

        return $show($workflow->create($siteId, [
            'provider_company_id' => $company($input['provider'] ?? null), 'external_provider_id' => $partner($input['external_provider'] ?? null),
            'transporter_company_id' => $company($input['transporter'] ?? null), 'external_transporter_id' => $partner($input['external_transporter'] ?? null),
        ], $input['requested_date'], array_map('strtoupper', $input['lots']), $request->user(),
            app(ReferenceCodes::class)->id('treatment_channels', $input['treatment_channel'] ?? null)))->response()->setStatusCode(201);
    })->name('pickups.store');

    // Before a pickup is requested: what does this provider ask of a collection of these lots, and is it met?
    Route::post('provider-requirements/check', function (Request $request, ProviderRequirements $requirements, TenantContext $context) {
        abort_unless($request->user()->can('pickups.request') || $request->user()->can('pickups.view'), 403);
        $input = $request->validate([
            // The receiver: a provider of the platform, or a partner without an account. Exactly one of the two.
            'provider' => ['required_without:external_provider', 'prohibits:external_provider', 'nullable', 'string', Rule::exists('companies', 'ulid')->where('company_type', 'provider')],
            'external_provider' => ['nullable', 'string', new TenantExists(ExternalPartner::class)],
            'lots' => ['required', 'array', 'min:1', 'max:500'],
            'lots.*' => ['required', 'string', new TenantExists(WasteLot::class)],
            'treatment_channel' => ['nullable', 'string', Rule::exists('treatment_channels', 'code')->where('is_active', true)],
        ]);
        $lots = array_map('strtoupper', $input['lots']);
        $result = $requirements->evaluate((int) $context->companyId(),
            isset($input['provider']) ? (int) Company::query()->where('ulid', strtoupper($input['provider']))->value('id') : null,
            isset($input['external_provider']) ? (int) ExternalPartner::query()->where('ulid', strtoupper($input['external_provider']))->value('id') : null,
            $requirements->linesOfLots((int) $context->companyId(), $lots, app(ReferenceCodes::class)->id('treatment_channels', $input['treatment_channel'] ?? null)));
        $siteId = (int) DB::table('waste_lots')->where('company_id', $context->companyId())->whereIn('ulid', $lots)->value('site_id');

        // `blocked`: the request would be refused. `may_override`: this person may send it all the same, with a reason.
        return ['data' => $result + ['may_override' => $request->user()->can('pickups.override_provider_requirements', $siteId)]];
    })->name('provider-requirements.check');

    Route::post('pickups/{pickup}/submit', function (Request $request, Pickup $pickup, PickupWorkflow $workflow, PickupNotifier $notifier) use ($allow, $show) {
        $allow($request, 'pickups.request', $pickup->site_id);
        // A blocking requirement of the receiver that fails refuses the request; someone entitled to may send it with a reason.
        $reason = $request->validate(['requirement_override_reason' => ['nullable', 'string', 'max:1000']])['requirement_override_reason'] ?? null;
        $pickup = $workflow->submit($pickup, $request->user(), $reason, $request->user()->can('pickups.override_provider_requirements', $pickup->site_id));
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
    Route::post('pickups/{pickup}/collect', function (Request $request, Pickup $pickup, PickupWorkflow $workflow, PickupNotifier $notifier, EvaluateStockThresholds $thresholds, ManifestWorkflow $manifests) use ($allow, $show) {
        $allow($request, 'pickups.load', $pickup->site_id);
        $input = $request->validate([
            'lots' => ['required', 'array', 'min:1'],
            'lots.*.lot' => ['required', 'string'],
            'lots.*.departure_weight_kg' => ['nullable', 'decimal:0,3', 'gte:0'],
            // Waste counted in pieces or litres: what leaves, in the unit of the line.
            'lots.*.departure_quantity' => ['nullable', 'decimal:0,3', 'gte:0'],
        ]);
        $quantities = collect($input['lots'])->filter(fn ($l) => isset($l['departure_quantity']))->mapWithKeys(fn ($l) => [strtoupper($l['lot']) => (string) $l['departure_quantity']])->all();
        $loaded = collect($input['lots'])->mapWithKeys(fn ($l) => [strtoupper($l['lot']) => isset($l['departure_weight_kg']) ? (string) $l['departure_weight_kg'] : null])->all();
        $pickup = $workflow->collect($pickup, $loaded, $request->user(), $quantities);
        $thresholds->forSite($pickup->site_id);
        // The receiver is told what left and, when it asks for something, whether that is still met on what left.
        $notifier->collected($pickup);
        // No waste leaves without its tracking slip: the ones not issued yet are issued now. A hazardous lot whose type
        // has no regulatory code gets none — the others get theirs — and a loading that has physically happened is
        // never undone for it.
        try {
            $manifests->generate($pickup, $request->user());
        } catch (BusinessRuleViolation) {
            // Reported on the pickup page, where the slips are listed, the missing codes named, and the slips issued again.
        }

        return $show($pickup);
    })->name('pickups.collect');

    // A blocking requirement of the receiver that was no longer met when the waste left: someone entitled to send a
    // pickup against such a requirement says why. It explains the departure; it never undoes it.
    Route::post('pickups/{pickup}/loading-requirements/acknowledge', function (Request $request, Pickup $pickup, ProviderRequirements $requirements) use ($allow, $show) {
        $allow($request, 'pickups.override_provider_requirements', $pickup->site_id);

        return $show($requirements->acknowledgeLoadingException($pickup, (string) $request->validate(['reason' => ['required', 'string', 'max:1000']])['reason'], $request->user()));
    })->name('pickups.loading-requirements.acknowledge');

    Route::post('pickups/{pickup}/acknowledge-variance', function (Request $request, Pickup $pickup, PickupWorkflow $workflow) use ($allow, $show) {
        $allow($request, 'pickups.complete', $pickup->site_id);

        return $show($workflow->acknowledgeVariance($pickup, (string) $request->validate(['comment' => ['required', 'string', 'max:1000']])['comment'], $request->user(),
            $request->user()->can('pickups.authorize_variance', $pickup->site_id)));
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
     * Receiver side (party scope: the pickup is only reachable if it is addressed to the provider). When the receiver
     * has no account, the industrial records these stages itself; nobody is notified of what it did itself.
     */
    Route::post('pickups/{pickup}/confirm', function (Request $request, Pickup $pickup, PickupWorkflow $workflow, PickupNotifier $notifier) use ($receiverStage, $show) {
        $onBehalf = $receiverStage($request, $pickup, 'provider.pickups.respond', 'pickups.request');
        $pickup = $workflow->confirm($pickup, (string) $request->validate(['confirmed_date' => ['required', 'date', 'after_or_equal:today']])['confirmed_date'], $request->user());
        $onBehalf || $notifier->confirmed($pickup);

        return $show($pickup);
    })->name('pickups.confirm');

    Route::post('pickups/{pickup}/refuse', function (Request $request, Pickup $pickup, PickupWorkflow $workflow, PickupNotifier $notifier) use ($receiverStage, $show) {
        $onBehalf = $receiverStage($request, $pickup, 'provider.pickups.respond', 'pickups.request');
        $pickup = $workflow->refuse($pickup, (string) $request->validate(['reason' => ['required', 'string', 'max:1000']])['reason'], $request->user());
        $onBehalf || $notifier->refused($pickup);

        return $show($pickup);
    })->name('pickups.refuse');

    Route::post('pickups/{pickup}/receive', function (Request $request, Pickup $pickup, PickupWorkflow $workflow, PickupNotifier $notifier) use ($receiverStage, $show) {
        $onBehalf = $receiverStage($request, $pickup, 'provider.pickups.receive', 'pickups.complete');
        $input = $request->validate([
            'lots' => ['required', 'array', 'min:1'],
            'lots.*.lot_number' => ['required', 'string'],
            // A weighed lot is received by its weight; a lot counted in pieces or litres by its quantity (a weight may be added).
            'lots.*.received_weight_kg' => ['required_without:lots.*.received_quantity', 'nullable', 'decimal:0,3', 'gte:0'],
            'lots.*.received_quantity' => ['nullable', 'decimal:0,3', 'gte:0'],
        ]);
        $of = fn (string $key) => collect($input['lots'])->filter(fn ($l) => isset($l[$key]))->mapWithKeys(fn ($l) => [$l['lot_number'] => (string) $l[$key]])->all();
        $pickup = $workflow->receive($pickup, $of('received_weight_kg'), $request->user(), $of('received_quantity'));
        $onBehalf || $notifier->received($pickup);

        return $show($pickup);
    })->name('pickups.receive');

    Route::post('pickups/{pickup}/treat', function (Request $request, Pickup $pickup, PickupWorkflow $workflow, ReferenceCodes $codes) use ($receiverStage, $show) {
        $receiverStage($request, $pickup, 'provider.pickups.receive', 'pickups.complete');
        $input = $request->validate([
            'lots' => ['required', 'array', 'min:1'],
            'lots.*.lot_number' => ['required', 'string'],
            'lots.*.channel' => ['required', 'string', Rule::exists('treatment_channels', 'code')->where('is_active', true)],
        ]);

        return $show($workflow->treat($pickup, collect($input['lots'])->mapWithKeys(fn ($l) => [$l['lot_number'] => $codes->id('treatment_channels', $l['channel'])])->all(), $request->user()));
    })->name('pickups.treat');
});
