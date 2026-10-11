<?php

use App\Modules\Catalog\Models\WasteType;
use App\Modules\Catalog\Support\Colors;
use App\Modules\Catalog\Support\UnitConversion;
use App\Modules\Common\Exceptions\BusinessRuleViolation;
use App\Modules\Common\Support\Constraint;
use App\Modules\Common\Support\ReferenceCodes;
use App\Modules\Identity\Authorization\AccessResolver;
use App\Modules\Sites\Actions\CreateSite;
use App\Modules\Sites\Actions\CreateStockThreshold;
use App\Modules\Sites\Actions\EvaluateStockThresholds;
use App\Modules\Sites\Enums\SiteKind;
use App\Modules\Sites\Models\Site;
use App\Modules\Sites\Models\StockThreshold;
use App\Modules\Sites\Models\Zone;
use App\Modules\Sites\Requests\StoreSiteRequest;
use App\Modules\Sites\Requests\StoreStockThresholdRequest;
use App\Modules\Sites\Resources\SiteResource;
use App\Modules\Sites\Resources\StockThresholdResource;
use App\Modules\Sites\Resources\ZoneResource;
use App\Modules\Sites\Support\StockQuery;
use App\Modules\Sites\Support\ZoneOrganisation;
use App\Modules\Tenancy\Rules\TenantExists;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;

Route::middleware(['auth:sanctum', 'tenant'])->group(function () {
    $allow = fn (Request $request, string $permission, ?int $siteId = null) => abort_unless($request->user()->can($permission, $siteId), 403);

    /*
     * M1-01: sites.
     */
    Route::get('sites', function (Request $request, AccessResolver $access) {
        $siteIds = $access->siteIdsFor($request->user(), 'sites.view');

        return SiteResource::collection(Site::query()->withCount('zones')
            ->when($siteIds !== null, fn ($query) => $query->whereIn('id', $siteIds))
            ->when(! $request->boolean('with_inactive'), fn ($query) => $query->where('is_active', true))
            ->when($request->string('search')->toString(), fn ($query, $value) => $query->where(fn ($q) => $q
                ->where('name', 'like', '%'.addcslashes($value, '%_\\').'%')->orWhere('code', 'like', addcslashes($value, '%_\\').'%')))
            ->orderBy('code')
            ->paginate(min((int) $request->integer('per_page', 25), 100)));
    })->name('sites.index');

    Route::post('sites', fn (StoreSiteRequest $request, CreateSite $action) => (new SiteResource(
        $action->handle($request->toData(), $request->user())->load('zones')
    ))->response()->setStatusCode(201))->name('sites.store');

    Route::get('sites/{site}', function (Request $request, Site $site) use ($allow) {
        $allow($request, 'sites.view', $site->id);

        return new SiteResource($site->load(['zones' => fn ($query) => $query->orderBy('code')]));
    })->name('sites.show');

    Route::put('sites/{site}', function (Request $request, Site $site) use ($allow) {
        $allow($request, 'sites.manage');
        $site->update($request->validate([
            'name' => ['required', 'string', 'max:150'],
            'site_kind' => ['required', Rule::enum(SiteKind::class)],
            'address_line1' => ['nullable', 'string', 'max:191'],
            'city' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'region' => ['nullable', 'string', 'max:100'],
            'timezone' => ['nullable', 'timezone:all'],
            'regulatory_identifier' => ['nullable', 'string', 'max:64'],
            'capacity_kg' => ['nullable', 'decimal:0,3', 'min:0'],
        ]));

        return new SiteResource($site->load('zones'));
    })->name('sites.update');

    // A site holding waste cannot be closed: its lots must be evacuated first. Never deleted (traceability).
    Route::post('sites/{site}/deactivate', function (Request $request, Site $site, StockQuery $stock) use ($allow) {
        $allow($request, 'sites.manage');
        if ($stock->lots([$site->id])->exists()) {
            throw new BusinessRuleViolation('SITE_HAS_STOCK', 'This site still holds waste lots.');
        }
        $site->forceFill(['is_active' => false, 'deactivated_at' => now()])->save();

        return new SiteResource($site);
    })->name('sites.deactivate');

    Route::post('sites/{site}/activate', function (Request $request, Site $site) use ($allow) {
        $allow($request, 'sites.manage');
        $site->forceFill(['is_active' => true, 'deactivated_at' => null])->save();

        return new SiteResource($site);
    })->name('sites.activate');

    /*
     * M1-01: zones of a site.
     */
    $zoneRules = fn (Site $site, ?Zone $zone = null) => [
        'code' => ['required', 'string', 'max:20', 'alpha_dash',
            Rule::unique('zones', 'code')->where('company_id', $site->company_id)->where('site_id', $site->id)->ignore($zone?->id)],
        'name' => ['required', 'string', 'max:150'],
        // Optional: a zone may simply have a name.
        'zone_type' => ['nullable', 'string', Rule::exists('zone_types', 'code')->where('is_active', true)],
        'capacity_kg' => ['nullable', 'decimal:0,3', 'min:0'],
        'capacity_m3' => ['nullable', 'decimal:0,3', 'min:0'],
        'capacity_alert_pct' => ['nullable', 'integer', 'between:1,100'],
        // How many lots of each packaging the zone holds when it contains only that packaging.
        'capacities' => ['sometimes', 'array', 'max:30'],
        'capacities.*.packaging_type' => ['required', 'string', 'distinct', Rule::exists('packaging_types', 'code')->where('is_active', true)],
        'capacities.*.max_count' => ['required', 'integer', 'between:1,100000'],
        // What the zone is meant for. Empty = anything; a lot that does not match is reported, never refused.
        'accepted_waste_types' => ['sometimes', 'array', 'max:200'],
        'accepted_waste_types.*' => ['string', 'distinct', new TenantExists(WasteType::class)],
        'accepted_colors' => ['sometimes', 'array', 'max:200'],
        'accepted_colors.*' => ['string', 'distinct', Colors::rule($site->company_id)],
    ];
    $zoneAttributes = function (array $input) {
        $type = isset($input['zone_type']) ? DB::table('zone_types')->where('code', $input['zone_type'])->first(['id', 'is_waste_storage']) : null;

        return ['zone_type_id' => $type?->id, 'is_waste_storage' => (bool) $type?->is_waste_storage, 'capacity_alert_pct' => $input['capacity_alert_pct'] ?? 90]
            + array_intersect_key($input, ['code' => true, 'name' => true, 'capacity_kg' => true, 'capacity_m3' => true]);
    };

    Route::post('sites/{site}/zones', function (Request $request, Site $site, ZoneOrganisation $organisation) use ($allow, $zoneRules, $zoneAttributes) {
        $allow($request, 'zones.manage', $site->id);
        $input = $request->validate($zoneRules($site));
        $zone = DB::transaction(function () use ($site, $input, $zoneAttributes, $organisation) {
            $zone = $site->zones()->create($zoneAttributes($input));
            $organisation->sync($zone, $input);

            return $zone;
        });

        return (new ZoneResource($zone))->response()->setStatusCode(201);
    })->name('zones.store');

    Route::put('zones/{zone}', function (Request $request, Zone $zone, ZoneOrganisation $organisation) use ($allow, $zoneRules, $zoneAttributes) {
        $allow($request, 'zones.manage', $zone->site_id);
        $input = $request->validate($zoneRules(Site::query()->findOrFail($zone->site_id), $zone));
        DB::transaction(function () use ($zone, $input, $zoneAttributes, $organisation) {
            $zone->update($zoneAttributes($input));
            $organisation->sync($zone, $input);
        });

        return new ZoneResource($zone);
    })->name('zones.update');

    Route::post('zones/{zone}/deactivate', function (Request $request, Zone $zone, StockQuery $stock) use ($allow) {
        $allow($request, 'zones.manage', $zone->site_id);
        if ($stock->lots([$zone->site_id])->where('l.zone_id', $zone->id)->exists()) {
            throw new BusinessRuleViolation('ZONE_HAS_STOCK', 'This zone still holds waste lots.');
        }
        $zone->update(['is_active' => false]);

        return new ZoneResource($zone);
    })->name('zones.deactivate');

    Route::post('zones/{zone}/activate', function (Request $request, Zone $zone) use ($allow) {
        $allow($request, 'zones.manage', $zone->site_id);
        $zone->update(['is_active' => true]);

        return new ZoneResource($zone);
    })->name('zones.activate');

    /*
     * M2-07: real-time stock by site, zone and waste type, with zone capacity and threshold state.
     */
    Route::get('stock', function (Request $request, AccessResolver $access, StockQuery $stock, ReferenceCodes $codes, ZoneOrganisation $organisation) {
        $siteIds = $access->siteIdsFor($request->user(), 'stock.view');
        abort_if($siteIds === [], 403);
        $filter = $request->validate([
            'filter.site' => ['nullable', 'string', 'ulid'],
            'filter.zone' => ['nullable', 'string', 'ulid'],
            'filter.waste_type' => ['nullable', 'string', 'ulid'],
            'filter.hazardous' => ['nullable', 'boolean'],
        ])['filter'] ?? [];

        $rows = $stock->lots($siteIds)
            ->join('sites as s', 's.id', '=', 'l.site_id')->join('zones as z', 'z.id', '=', 'l.zone_id')->join('waste_types as t', 't.id', '=', 'l.waste_type_id')
            ->when($filter['site'] ?? null, fn ($query, $value) => $query->where('s.ulid', strtoupper($value)))
            ->when($filter['zone'] ?? null, fn ($query, $value) => $query->where('z.ulid', strtoupper($value)))
            ->when($filter['waste_type'] ?? null, fn ($query, $value) => $query->where('t.ulid', strtoupper($value)))
            ->when(array_key_exists('hazardous', $filter) && $filter['hazardous'] !== null, fn ($query) => $query->where('l.is_hazardous', (bool) $filter['hazardous']))
            ->groupBy('l.site_id', 'l.zone_id', 'l.waste_type_id', 's.ulid', 's.name', 'z.ulid', 'z.name', 'z.capacity_kg', 'z.capacity_alert_pct',
                't.ulid', 't.name', 't.is_hazardous', 't.default_unit_id', 't.density_kg_m3', 't.unit_weight_kg')
            ->orderBy('s.name')->orderBy('z.name')->orderBy('t.name')
            ->get([
                'l.site_id', 'l.zone_id', 'l.waste_type_id', 's.ulid as site', 's.name as site_name', 'z.ulid as zone', 'z.name as zone_name',
                'z.capacity_kg', 'z.capacity_alert_pct', 't.ulid as waste_type', 't.name as waste_type_name', 't.is_hazardous',
                't.default_unit_id', 't.density_kg_m3', 't.unit_weight_kg',
                DB::raw('COALESCE(SUM(l.net_weight_kg), 0) as quantity_kg'), DB::raw('COUNT(*) as lots'),
                DB::raw('SUM(l.net_weight_kg IS NULL) as unweighed_lots'),
                DB::raw("COALESCE(SUM(CASE WHEN l.status = 'awaiting_pickup' THEN l.net_weight_kg ELSE 0 END), 0) as awaiting_pickup_kg"),
                DB::raw('MIN(l.generated_at) as oldest_lot_at'),
            ]);

        // How full each zone is, counted on everything it holds whatever the filters applied to the rows.
        $fills = $rows->unique('zone_id')->mapWithKeys(fn ($row) => [$row->zone_id => $organisation->fill(
            (int) $row->site_id, (int) $row->zone_id, $row->capacity_kg === null ? null : (string) $row->capacity_kg, (int) $row->capacity_alert_pct)]);
        $thresholds = StockThreshold::query()->where('is_active', true)->when($siteIds !== null, fn ($query) => $query->whereIn('site_id', $siteIds))
            ->get(['ulid', 'site_id', 'zone_id', 'waste_type_id', 'max_quantity_kg', 'warning_pct']);

        return response()->json([
            'data' => $rows->map(function ($row) use ($fills, $codes) {
                $capacity = $row->capacity_kg === null || bccomp((string) $row->capacity_kg, '0', 3) === 0 ? null : (string) $row->capacity_kg;
                $unit = $codes->code('units', $row->default_unit_id);

                return [
                    // M1-05: the same quantity in the unit this waste type is counted in (null when it cannot be converted).
                    'unit' => $unit,
                    'quantity_in_unit' => UnitConversion::fromKg((string) $row->quantity_kg, $unit, $row->density_kg_m3, $row->unit_weight_kg),
                    'site' => $row->site, 'site_name' => $row->site_name, 'zone' => $row->zone, 'zone_name' => $row->zone_name,
                    'waste_type' => $row->waste_type, 'waste_type_name' => $row->waste_type_name, 'is_hazardous' => (bool) $row->is_hazardous,
                    'quantity_kg' => (string) $row->quantity_kg, 'awaiting_pickup_kg' => (string) $row->awaiting_pickup_kg, 'lots' => (int) $row->lots,
                    // Lots of this line nobody weighed: they are in `lots`, not in `quantity_kg`.
                    'unweighed_lots' => (int) $row->unweighed_lots,
                    'oldest_lot_at' => $row->oldest_lot_at,
                    'zone_quantity_kg' => $fills[$row->zone_id]['quantity_kg'],
                    'zone_capacity_kg' => $capacity,
                    'zone_alert_level' => $fills[$row->zone_id]['alert_level'],
                    'zone_fill' => $fills[$row->zone_id],
                ];
            }),
            // Each threshold with the stock it currently measures, whatever the filters applied to the rows.
            'thresholds' => $thresholds->map(function (StockThreshold $threshold) use ($stock) {
                $quantity = $stock->quantity($threshold->site_id, $threshold->zone_id, $threshold->waste_type_id);

                return [
                    'ulid' => $threshold->ulid,
                    'quantity_kg' => $quantity,
                    'unweighed_lots' => $stock->unweighed($threshold->site_id, $threshold->zone_id, $threshold->waste_type_id),
                    'max_quantity_kg' => (string) $threshold->max_quantity_kg,
                    'alert_level' => EvaluateStockThresholds::level($quantity, (string) $threshold->max_quantity_kg, $threshold->warning_pct,
                        $stock->unweighed($threshold->site_id, $threshold->zone_id, $threshold->waste_type_id))?->value,
                    // The limit answered on its own, in its unit. Known weight above it: exceeded, and the lots nobody weighed
                    // are named too. Known weight below it with such lots: unverifiable, never "respected". A stock limit
                    // alerts; it blocks nothing.
                    'constraint' => Constraint::quantity('stock', 'kg', $quantity, (string) $threshold->max_quantity_kg, $threshold->warning_pct,
                        $stock->unweighedNumbers($threshold->site_id, $threshold->zone_id, $threshold->waste_type_id)),
                ];
            }),
            'meta' => ['total_kg' => $rows->reduce(fn ($sum, $row) => bcadd($sum, (string) $row->quantity_kg, 3), '0'), 'unweighed_lots' => (int) $rows->sum('unweighed_lots'), 'generated_at' => now()->toIso8601ZuluString()],
        ]);
    })->name('stock.index');

    /*
     * Stock thresholds.
     */
    $with = ['site:id,ulid,name', 'zone:id,ulid,name', 'wasteType:id,ulid,name'];

    Route::get('stock-thresholds', function (Request $request, AccessResolver $access) use ($with) {
        $siteIds = $access->siteIdsFor($request->user(), 'stock.view');

        return StockThresholdResource::collection(StockThreshold::query()->with($with)
            ->when($siteIds !== null, fn ($query) => $query->whereIn('site_id', $siteIds))
            ->orderBy('id')
            ->paginate(min((int) $request->integer('per_page', 25), 100)));
    })->name('stock-thresholds.index');

    Route::post('stock-thresholds', function (StoreStockThresholdRequest $request, CreateStockThreshold $action, EvaluateStockThresholds $evaluate) use ($with) {
        $threshold = $action->handle($request->toData(), $request->user());
        // The stock may already be above the new threshold.
        $evaluate->forSite($threshold->site_id);

        return (new StockThresholdResource($threshold->refresh()->load($with)))->response()->setStatusCode(201);
    })->name('stock-thresholds.store');

    Route::put('stock-thresholds/{threshold}', function (Request $request, StockThreshold $threshold, EvaluateStockThresholds $evaluate) use ($allow, $with) {
        $allow($request, 'stock.thresholds.manage', $threshold->site_id);
        $input = $request->validate([
            'max_quantity_kg' => ['required', 'decimal:0,3', 'gt:0'],
            'warning_pct' => ['required', 'integer', 'between:1,100'],
            'is_active' => ['required', 'boolean'],
        ]);
        $threshold->forceFill($input)->save();
        $evaluate->forSite($threshold->site_id);

        return new StockThresholdResource($threshold->refresh()->load($with));
    })->name('stock-thresholds.update');

    Route::delete('stock-thresholds/{threshold}', function (Request $request, StockThreshold $threshold) use ($allow) {
        $allow($request, 'stock.thresholds.manage', $threshold->site_id);
        $threshold->delete();

        return response()->noContent();
    })->name('stock-thresholds.destroy');
});
