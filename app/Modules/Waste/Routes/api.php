<?php

use App\Modules\Sites\Models\Zone;
use App\Modules\Tenancy\Rules\TenantExists;
use App\Modules\Waste\Actions\CreateWasteLot;
use App\Modules\Waste\Actions\TransformLots;
use App\Modules\Waste\Actions\TransitionLot;
use App\Modules\Waste\DTOs\GroupWasteLotsData;
use App\Modules\Waste\DTOs\SplitWasteLotData;
use App\Modules\Waste\Enums\LotStatus;
use App\Modules\Waste\Models\WasteLot;
use App\Modules\Waste\Models\WasteLotEvent;
use App\Modules\Waste\Requests\StoreWasteLotRequest;
use App\Modules\Waste\Resources\WasteLotResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'tenant'])->group(function () {
    Route::post('lots', fn (StoreWasteLotRequest $request, CreateWasteLot $action) => (new WasteLotResource(
        $action->handle($request->toData(), $request->user())
    ))->response()->setStatusCode(201))->name('lots.store');

    Route::get('lots/{lot}', function (Request $request, WasteLot $lot) {
        abort_unless($request->user()->can('lots.view', $lot->site_id), 403);

        return new WasteLotResource($lot);
    })->name('lots.show');

    Route::get('lots/{lot}/events', function (Request $request, WasteLot $lot) {
        abort_unless($request->user()->can('lots.view', $lot->site_id), 403);

        return ['data' => WasteLotEvent::query()->where('waste_lot_id', $lot->id)->orderBy('occurred_at')->orderBy('id')
            ->get(['event_type', 'from_status', 'to_status', 'weight_kg', 'payload', 'source', 'occurred_at'])];
    })->name('lots.events');

    Route::post('lots/{lot}/store', function (Request $request, WasteLot $lot, TransitionLot $action) {
        abort_unless($request->user()->can('lots.move', $lot->site_id), 403);
        $zone = $request->validate(['zone' => ['nullable', 'string', new TenantExists(Zone::class, fn ($q) => $q->where('site_id', $lot->site_id))]])['zone'] ?? null;
        $zoneId = $zone === null ? null : (int) Zone::query()->where('ulid', strtoupper($zone))->value('id');

        return new WasteLotResource($action->handle($lot, LotStatus::Stored, $request->user(), $zoneId));
    })->name('lots.store-in-zone');

    Route::post('lots/{lot}/split', function (Request $request, WasteLot $lot, TransformLots $action) {
        abort_unless($request->user()->can('lots.split', $lot->site_id), 403);
        $input = $request->validate([
            'outputs' => ['required', 'array', 'min:2'],
            'outputs.*.net_weight_kg' => ['required', 'decimal:0,3', 'gt:0'],
            'outputs.*.zone' => ['nullable', 'string', new TenantExists(Zone::class, fn ($q) => $q->where('site_id', $lot->site_id))],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $outputs = array_map(fn (array $o) => ['net_weight_kg' => (string) $o['net_weight_kg'], 'zone' => isset($o['zone']) ? strtoupper($o['zone']) : null], $input['outputs']);

        return WasteLotResource::collection($action->split($lot, new SplitWasteLotData($outputs, $input['notes'] ?? null), $request->user()))
            ->response()->setStatusCode(201);
    })->name('lots.split');

    Route::post('lots/group', function (Request $request, TransformLots $action) {
        $input = $request->validate([
            'lots' => ['required', 'array', 'min:2'],
            'lots.*' => ['required', 'string', new TenantExists(WasteLot::class)],
            'zone' => ['required', 'string', new TenantExists(Zone::class)],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $ulids = array_map('strtoupper', $input['lots']);
        $siteId = WasteLot::query()->where('ulid', $ulids[0])->value('site_id');
        abort_unless($request->user()->can('lots.group', (int) $siteId), 403);

        return (new WasteLotResource($action->group(new GroupWasteLotsData($ulids, strtoupper($input['zone']), $input['notes'] ?? null), $request->user())))
            ->response()->setStatusCode(201);
    })->name('lots.group');
});
