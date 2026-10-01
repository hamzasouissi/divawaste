<?php

use App\Modules\Pickups\Actions\PickupWorkflow;
use App\Modules\Pickups\Models\Pickup;
use App\Modules\Pickups\Resources\PickupResource;
use App\Modules\Sites\Models\Site;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Rules\TenantExists;
use App\Modules\Waste\Models\WasteLot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;

Route::middleware(['auth:sanctum', 'tenant'])->group(function () {
    $allow = fn (Request $request, string $permission, ?int $siteId = null) => abort_unless($request->user()->can($permission, $siteId), 403);

    Route::get('pickups/{pickup}', function (Request $request, Pickup $pickup) {
        abort_unless($request->user()->can('pickups.view', $pickup->site_id) || $request->user()->can('provider.pickups.view'), 403);

        return new PickupResource($pickup);
    })->name('pickups.show');

    // Industrial side.
    Route::post('pickups', function (Request $request, PickupWorkflow $workflow) use ($allow) {
        $input = $request->validate([
            'site' => ['required', 'string', new TenantExists(Site::class)],
            'provider' => ['required', 'string', Rule::exists('companies', 'ulid')->where('company_type', 'provider')],
            'transporter' => ['nullable', 'string', Rule::exists('companies', 'ulid')->where('company_type', 'provider')],
            'requested_date' => ['required', 'date', 'after_or_equal:today'],
            'lots' => ['required', 'array', 'min:1'],
            'lots.*' => ['required', 'string', new TenantExists(WasteLot::class)],
        ]);
        $siteId = (int) Site::query()->where('ulid', strtoupper($input['site']))->value('id');
        $allow($request, 'pickups.request', $siteId);
        $company = fn (?string $ulid) => $ulid === null ? null : (int) Company::query()->where('ulid', strtoupper($ulid))->value('id');

        return (new PickupResource($workflow->create($siteId, (int) $company($input['provider']), $company($input['transporter'] ?? null),
            $input['requested_date'], array_map('strtoupper', $input['lots']), $request->user())))->response()->setStatusCode(201);
    })->name('pickups.store');

    Route::post('pickups/{pickup}/submit', function (Request $request, Pickup $pickup, PickupWorkflow $workflow) use ($allow) {
        $allow($request, 'pickups.request', $pickup->site_id);

        return new PickupResource($workflow->submit($pickup, $request->user()));
    })->name('pickups.submit');

    Route::post('pickups/{pickup}/cancel', function (Request $request, Pickup $pickup, PickupWorkflow $workflow) use ($allow) {
        $allow($request, 'pickups.cancel', $pickup->site_id);

        return new PickupResource($workflow->cancel($pickup, (string) $request->validate(['reason' => ['required', 'string', 'max:1000']])['reason'], $request->user()));
    })->name('pickups.cancel');

    Route::post('pickups/{pickup}/collect', function (Request $request, Pickup $pickup, PickupWorkflow $workflow) use ($allow) {
        $allow($request, 'pickups.load', $pickup->site_id);
        $input = $request->validate(['lots' => ['required', 'array', 'min:1'], 'lots.*.lot' => ['required', 'string'], 'lots.*.departure_weight_kg' => ['nullable', 'decimal:0,3', 'gte:0']]);
        $loaded = collect($input['lots'])->mapWithKeys(fn ($l) => [strtoupper($l['lot']) => isset($l['departure_weight_kg']) ? (string) $l['departure_weight_kg'] : null])->all();

        return new PickupResource($workflow->collect($pickup, $loaded, $request->user()));
    })->name('pickups.collect');

    // Provider side (party scope).
    Route::post('pickups/{pickup}/confirm', function (Request $request, Pickup $pickup, PickupWorkflow $workflow) use ($allow) {
        $allow($request, 'provider.pickups.respond');

        return new PickupResource($workflow->confirm($pickup, (string) $request->validate(['confirmed_date' => ['required', 'date', 'after_or_equal:today']])['confirmed_date'], $request->user()));
    })->name('pickups.confirm');

    Route::post('pickups/{pickup}/refuse', function (Request $request, Pickup $pickup, PickupWorkflow $workflow) use ($allow) {
        $allow($request, 'provider.pickups.respond');

        return new PickupResource($workflow->refuse($pickup, (string) $request->validate(['reason' => ['required', 'string', 'max:1000']])['reason'], $request->user()));
    })->name('pickups.refuse');

    Route::post('pickups/{pickup}/receive', function (Request $request, Pickup $pickup, PickupWorkflow $workflow) use ($allow) {
        $allow($request, 'provider.pickups.receive');
        $input = $request->validate(['lots' => ['required', 'array', 'min:1'], 'lots.*.lot_number' => ['required', 'string'], 'lots.*.received_weight_kg' => ['required', 'decimal:0,3', 'gte:0']]);

        return new PickupResource($workflow->receive($pickup, collect($input['lots'])->mapWithKeys(fn ($l) => [$l['lot_number'] => (string) $l['received_weight_kg']])->all(), $request->user()));
    })->name('pickups.receive');
});
