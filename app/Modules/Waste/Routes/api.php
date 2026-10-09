<?php

use App\Modules\Common\Exceptions\BusinessRuleViolation;
use App\Modules\Common\Support\DocumentLanguage;
use App\Modules\Identity\Authorization\AccessResolver;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Production\Models\RawMaterial;
use App\Modules\Production\Support\LotOrigin;
use App\Modules\Sites\Actions\EvaluateStockThresholds;
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
use App\Modules\Waste\Support\LotLabel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;

Route::middleware(['auth:sanctum', 'tenant'])->group(function () {
    /*
     * Lot register: search, filters, sort, pagination (server side — the table holds millions of rows).
     */
    Route::get('lots', function (Request $request, AccessResolver $access) {
        $siteIds = $access->siteIdsFor($request->user(), 'lots.view');
        abort_if($siteIds === [], 403);
        $input = $request->validate([
            'filter.status' => ['nullable', 'array'],
            'filter.status.*' => [Rule::enum(LotStatus::class)],
            'filter.site' => ['nullable', 'string', 'ulid'],
            'filter.zone' => ['nullable', 'string', 'ulid'],
            'filter.waste_type' => ['nullable', 'string', 'ulid'],
            'filter.hazardous' => ['nullable', 'boolean'],
            'filter.on_site' => ['nullable', 'boolean'],
            'filter.from' => ['nullable', 'date'],
            'filter.to' => ['nullable', 'date', 'after_or_equal:filter.from'],
            'filter.search' => ['nullable', 'string', 'max:64'],
            'filter.production_order' => ['nullable', 'string', 'ulid'],
            'filter.raw_material' => ['nullable', 'string', 'ulid'],
            // Lots still waiting for their weight.
            'filter.unweighed' => ['nullable', 'boolean'],
            'sort' => ['nullable', Rule::in(['generated_at', '-generated_at', 'net_weight_kg', '-net_weight_kg', 'lot_number', '-lot_number'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $filter = $input['filter'] ?? [];
        $sort = $input['sort'] ?? '-generated_at';

        return WasteLotResource::collection(WasteLot::query()->with(WasteLotResource::RELATIONS)
            ->when($siteIds !== null, fn ($query) => $query->whereIn('site_id', $siteIds))
            ->when($filter['status'] ?? null, fn ($query, $value) => $query->whereIn('status', $value))
            ->when($filter['on_site'] ?? false, fn ($query) => $query->whereIn('status', [LotStatus::Created, LotStatus::Stored, LotStatus::AwaitingPickup]))
            ->when($filter['site'] ?? null, fn ($query, $value) => $query->whereRelation('site', 'ulid', strtoupper($value)))
            ->when($filter['zone'] ?? null, fn ($query, $value) => $query->whereRelation('zone', 'ulid', strtoupper($value)))
            ->when($filter['waste_type'] ?? null, fn ($query, $value) => $query->whereRelation('wasteType', 'ulid', strtoupper($value)))
            ->when(isset($filter['hazardous']), fn ($query) => $query->where('is_hazardous', (bool) $filter['hazardous']))
            ->when($filter['from'] ?? null, fn ($query, $value) => $query->where('generated_at', '>=', $value))
            ->when($filter['to'] ?? null, fn ($query, $value) => $query->where('generated_at', '<', now()->parse($value)->addDay()->startOfDay()))
            ->when($filter['production_order'] ?? null, fn ($query, $value) => $query->whereIn('production_order_id', ProductionOrder::query()->select('id')->where('ulid', strtoupper($value))))
            ->when($filter['unweighed'] ?? false, fn ($query) => $query->whereNull('net_weight_kg'))
            ->when($filter['raw_material'] ?? null, fn ($query, $value) => $query->whereIn('raw_material_id', RawMaterial::query()->select('id')->where('ulid', strtoupper($value))))
            // Lot numbers are searched by prefix so the (company_id, lot_number) index is used.
            ->when($filter['search'] ?? null, fn ($query, $value) => $query->where('lot_number', 'like', addcslashes(strtoupper($value), '%_\\').'%'))
            ->orderBy(ltrim($sort, '-'), str_starts_with($sort, '-') ? 'desc' : 'asc')->orderByDesc('id')
            ->paginate(min((int) $request->integer('per_page', 25), 100)));
    })->name('lots.index');

    Route::post('lots', function (StoreWasteLotRequest $request, CreateWasteLot $action, EvaluateStockThresholds $thresholds) {
        $lot = $action->handle($request->toData(), $request->user());
        $thresholds->forSite($lot->site_id);

        return (new WasteLotResource($lot))->response()->setStatusCode(201);
    })->name('lots.store');

    /*
     * M2-02: what a scanned QR (or, later, RFID) tag designates. Tags of another company are simply unknown.
     * The lot number printed on the label is accepted too: it is what an operator types when the camera cannot read.
     */
    Route::get('lots/scan/{tag}', function (Request $request, string $tag) {
        abort_if(strlen($tag) > 64, 404);
        $lot = WasteLot::query()->whereHas('tags', fn ($query) => $query->where('tag_value', $tag)->where('is_active_flag', true))->first()
            ?? WasteLot::query()->where('lot_number', strtoupper(trim($tag)))->firstOrFail();
        abort_unless($request->user()->can('lots.view', $lot->site_id), 403);

        return new WasteLotResource($lot);
    })->name('lots.scan');

    Route::get('lots/{lot}', function (Request $request, WasteLot $lot) {
        abort_unless($request->user()->can('lots.view', $lot->site_id), 403);

        $materials = DB::table('waste_lot_compositions as c')->join('materials as m', 'm.id', '=', 'c.material_id')
            ->where('c.waste_lot_id', $lot->id)->orderByDesc('c.percentage')->get(['m.code as material', 'm.name', 'c.percentage as pct'])
            ->map(fn ($row) => ['material' => $row->material, 'name' => json_decode((string) $row->name, true), 'pct' => (string) $row->pct]);
        $lineage = fn (string $mine, string $other) => DB::table('waste_lot_lineage as g')->join('waste_lots as l', 'l.id', '=', "g.{$other}")
            ->where("g.{$mine}", $lot->id)->orderBy('g.id')
            ->get(['l.ulid', 'l.lot_number', 'l.status', 'g.relation_type', 'g.quantity_kg']);
        $pickup = DB::table('pickup_lots as pl')->join('pickups as p', 'p.id', '=', 'pl.pickup_id')
            ->leftJoin('companies as c', 'c.id', '=', 'p.provider_company_id')->leftJoin('external_partners as xp', 'xp.id', '=', 'p.external_provider_id')
            ->where('pl.waste_lot_id', $lot->id)->orderByDesc('pl.id')
            ->first(['p.ulid', 'p.pickup_number', 'p.status', DB::raw('COALESCE(c.legal_name, xp.legal_name) as provider'), 'pl.line_status', 'pl.departure_weight_kg', 'pl.received_weight_kg']);

        return (new WasteLotResource($lot))->additional(['meta' => [
            'composition' => $materials,
            'parents' => $lineage('child_lot_id', 'parent_lot_id'),
            'children' => $lineage('parent_lot_id', 'child_lot_id'),
            'pickup' => $pickup,
        ]]);
    })->name('lots.show');

    // M2-05: every status change and movement, with the time and the person.
    // Where the lot comes from in production can be given, or corrected, after it was created: the operator on the
    // floor does not always know the OF. Its weight, type and trail do not change.
    Route::put('lots/{lot}/origin', function (Request $request, WasteLot $lot, LotOrigin $origin) {
        abort_unless($request->user()->can('lots.update', $lot->site_id), 403);
        $input = $request->validate(LotOrigin::rules());
        $lot->forceFill($origin->resolve($input, (int) $lot->site_id, (int) $lot->zone_id) + ['updated_by_user_id' => $request->user()->id])->save();

        return new WasteLotResource($lot);
    })->name('lots.origin');

    Route::get('lots/{lot}/events', function (Request $request, WasteLot $lot) {
        abort_unless($request->user()->can('lots.view', $lot->site_id), 403);

        return ['data' => DB::table('waste_lot_events as e')
            ->leftJoin('users as u', 'u.id', '=', 'e.user_id')
            ->leftJoin('zones as fz', 'fz.id', '=', 'e.from_zone_id')->leftJoin('zones as tz', 'tz.id', '=', 'e.to_zone_id')
            ->leftJoin('pickups as p', 'p.id', '=', 'e.pickup_id')
            ->where('e.waste_lot_id', $lot->id)->where('e.company_id', $lot->company_id)
            ->orderBy('e.occurred_at')->orderBy('e.id')
            ->get(['e.event_type', 'e.from_status', 'e.to_status', 'e.weight_kg', 'e.payload', 'e.source', 'e.occurred_at',
                'u.first_name', 'u.last_name', 'fz.name as from_zone', 'tz.name as to_zone', 'p.pickup_number'])
            ->map(fn ($row) => [
                'event_type' => $row->event_type, 'from_status' => $row->from_status, 'to_status' => $row->to_status,
                'weight_kg' => $row->weight_kg, 'payload' => json_decode((string) $row->payload, true), 'source' => $row->source,
                'occurred_at' => now()->parse($row->occurred_at, 'UTC')->toIso8601ZuluString('millisecond'),
                'actor' => $row->first_name === null ? null : trim("{$row->first_name} {$row->last_name}"),
                'from_zone' => $row->from_zone, 'to_zone' => $row->to_zone, 'pickup_number' => $row->pickup_number,
            ])];
    })->name('lots.events');

    // M2-02: thermal label, 100 × 50 mm.
    Route::get('lots/{lot}/label', function (Request $request, WasteLot $lot) {
        abort_unless($request->user()->can('lots.print_label', $lot->site_id), 403);
        $pdf = DocumentLanguage::during(DocumentLanguage::of($request), fn () => LotLabel::render($lot));
        LotLabel::recordPrint($lot, $request->user()->id);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="label-'.$lot->lot_number.'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    })->name('lots.label');

    Route::post('lots/{lot}/store', function (Request $request, WasteLot $lot, TransitionLot $action, EvaluateStockThresholds $thresholds) {
        abort_unless($request->user()->can('lots.move', $lot->site_id), 403);
        $zone = $request->validate(['zone' => ['nullable', 'string', new TenantExists(Zone::class, fn ($q) => $q->where('site_id', $lot->site_id)->where('is_active', true))]])['zone'] ?? null;
        $zoneId = $zone === null ? null : (int) Zone::query()->where('ulid', strtoupper($zone))->value('id');

        // Storing a lot already stored elsewhere is a move between zones, not a status change.
        $lot = $lot->status === LotStatus::Stored && $zoneId !== null && $zoneId !== $lot->zone_id
            ? DB::transaction(function () use ($lot, $zoneId, $request) {
                $from = $lot->zone_id;
                $lot->forceFill(['zone_id' => $zoneId, 'updated_by_user_id' => $request->user()->id])->save();
                WasteLotEvent::record($lot, 'moved', ['from_zone_id' => $from, 'to_zone_id' => $zoneId, 'user_id' => $request->user()->id]);

                return $lot;
            })
            : $action->handle($lot, LotStatus::Stored, $request->user(), $zoneId);
        $thresholds->forSite($lot->site_id);

        return new WasteLotResource($lot);
    })->name('lots.store-in-zone');

    // M2-04: corrected weighing of a lot still on site. The previous weight stays in the history.
    Route::post('lots/{lot}/weigh', function (Request $request, WasteLot $lot, EvaluateStockThresholds $thresholds) {
        abort_unless($request->user()->can('lots.weigh', $lot->site_id), 403);
        $input = $request->validate([
            'net_weight_kg' => ['required', 'decimal:0,3', 'gte:0'],
            'gross_weight_kg' => ['nullable', 'decimal:0,3', 'gte:0'],
            'tare_weight_kg' => ['nullable', 'decimal:0,3', 'gte:0'],
            // A guess of the person rather than a reading: kept as such, and not accepted where a measure is required.
            'estimated' => ['sometimes', 'boolean'],
            'version' => ['nullable', 'integer'],
        ]);

        $lot = DB::transaction(function () use ($lot, $input, $request) {
            $lot = WasteLot::query()->whereKey($lot->id)->lockForUpdate()->firstOrFail();
            if (! $lot->status->isTransformable()) {
                throw new BusinessRuleViolation('LOT_NOT_WEIGHABLE', 'Only a lot still on site and not yet in a pickup can be weighed again.');
            }
            if (isset($input['version']) && (int) $input['version'] !== $lot->version) {
                throw new BusinessRuleViolation('LOT_VERSION_CONFLICT', 'This lot was modified in the meantime.');
            }
            $previous = $lot->net_weight_kg === null ? null : (string) $lot->net_weight_kg;
            $basis = ($input['estimated'] ?? false) ? 'estimated' : 'measured';
            $lot->forceFill([
                'net_weight_kg' => $input['net_weight_kg'], 'gross_weight_kg' => $input['gross_weight_kg'] ?? null,
                'tare_weight_kg' => $input['tare_weight_kg'] ?? null, 'weight_basis' => $basis, 'updated_by_user_id' => $request->user()->id,
            ])->save();
            WasteLotEvent::record($lot, 'weighed', [
                'weight_kg' => $input['net_weight_kg'], 'gross_weight_kg' => $input['gross_weight_kg'] ?? null,
                'tare_weight_kg' => $input['tare_weight_kg'] ?? null, 'weight_source' => 'manual',
                'payload' => ['previous_weight_kg' => $previous, 'weight_basis' => $basis], 'user_id' => $request->user()->id,
            ]);

            return $lot;
        });
        $thresholds->forSite($lot->site_id);

        return new WasteLotResource($lot);
    })->name('lots.weigh');

    // M2-06.
    Route::post('lots/{lot}/split', function (Request $request, WasteLot $lot, TransformLots $action, EvaluateStockThresholds $thresholds) {
        abort_unless($request->user()->can('lots.split', $lot->site_id), 403);
        $input = $request->validate([
            'outputs' => ['required', 'array', 'min:2', 'max:50'],
            'outputs.*.net_weight_kg' => ['required', 'decimal:0,3', 'gt:0'],
            'outputs.*.zone' => ['nullable', 'string', new TenantExists(Zone::class, fn ($q) => $q->where('site_id', $lot->site_id))],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $outputs = array_map(fn (array $o) => ['net_weight_kg' => (string) $o['net_weight_kg'], 'zone' => isset($o['zone']) ? strtoupper($o['zone']) : null], $input['outputs']);
        $children = $action->split($lot, new SplitWasteLotData($outputs, $input['notes'] ?? null), $request->user());
        $thresholds->forSite($lot->site_id);

        return WasteLotResource::collection($children)->response()->setStatusCode(201);
    })->name('lots.split');

    Route::post('lots/group', function (Request $request, TransformLots $action, EvaluateStockThresholds $thresholds) {
        $input = $request->validate([
            'lots' => ['required', 'array', 'min:2', 'max:200'],
            'lots.*' => ['required', 'string', new TenantExists(WasteLot::class)],
            'zone' => ['required', 'string', new TenantExists(Zone::class)],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $ulids = array_map('strtoupper', $input['lots']);
        $siteId = (int) WasteLot::query()->where('ulid', $ulids[0])->value('site_id');
        abort_unless($request->user()->can('lots.group', $siteId), 403);
        $group = $action->group(new GroupWasteLotsData($ulids, strtoupper($input['zone']), $input['notes'] ?? null), $request->user());
        $thresholds->forSite($siteId);

        return (new WasteLotResource($group))->response()->setStatusCode(201);
    })->name('lots.group');
});
