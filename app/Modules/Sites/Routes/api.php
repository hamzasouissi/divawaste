<?php

use App\Modules\Catalog\Models\WasteType;
use App\Modules\Catalog\Support\Colors;
use App\Modules\Catalog\Support\UnitConversion;
use App\Modules\Common\Exceptions\BusinessRuleViolation;
use App\Modules\Common\Support\Constraint;
use App\Modules\Common\Support\Measure;
use App\Modules\Common\Support\ReferenceCodes;
use App\Modules\Identity\Authorization\AccessResolver;
use App\Modules\Sites\Actions\CreateSite;
use App\Modules\Sites\Actions\CreateStockThreshold;
use App\Modules\Sites\Actions\EvaluateCapacityAlerts;
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
use App\Modules\Sites\Support\StockLimits;
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
    $zoneRules = fn (Site $site, ?Zone $zone = null, ?Request $request = null) => [
        'code' => ['required', 'string', 'max:20', 'alpha_dash',
            Rule::unique('zones', 'code')->where('company_id', $site->company_id)->where('site_id', $site->id)->ignore($zone?->id)],
        'name' => ['required', 'string', 'max:150'],
        // Optional: a zone may simply have a name.
        'zone_type' => ['nullable', 'string', Rule::exists('zone_types', 'code')->where('is_active', true)],
        'capacity_kg' => ['nullable', 'decimal:0,3', 'min:0'],
        'capacity_m3' => ['nullable', 'decimal:0,3', 'min:0', 'required_if:space_method,volume', Rule::when($request?->input('space_method') === 'volume', ['gt:0'])],
        // How the room of the zone is measured: packages per packaging, or volume. One or the other.
        'space_method' => ['sometimes', Rule::in(['packaging', 'volume'])],
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
            + array_intersect_key($input, ['code' => true, 'name' => true, 'capacity_kg' => true, 'capacity_m3' => true, 'space_method' => true]);
    };

    Route::post('sites/{site}/zones', function (Request $request, Site $site, ZoneOrganisation $organisation) use ($allow, $zoneRules, $zoneAttributes) {
        $allow($request, 'zones.manage', $site->id);
        $input = $request->validate($zoneRules($site, null, $request));
        $zone = DB::transaction(function () use ($site, $input, $zoneAttributes, $organisation) {
            $zone = $site->zones()->create($zoneAttributes($input));
            $organisation->sync($zone, $input);

            return $zone;
        });
        app(EvaluateCapacityAlerts::class)->forSite($site->id);

        return (new ZoneResource($zone))->response()->setStatusCode(201);
    })->name('zones.store');

    Route::put('zones/{zone}', function (Request $request, Zone $zone, ZoneOrganisation $organisation) use ($allow, $zoneRules, $zoneAttributes) {
        $allow($request, 'zones.manage', $zone->site_id);
        $input = $request->validate($zoneRules(Site::query()->findOrFail($zone->site_id), $zone, $request));
        DB::transaction(function () use ($zone, $input, $zoneAttributes, $organisation) {
            $zone->update($zoneAttributes($input));
            $organisation->sync($zone, $input);
        });
        // What the zone already holds may be above its new limits.
        app(EvaluateCapacityAlerts::class)->forSite($zone->site_id);

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
    Route::get('stock', function (Request $request, AccessResolver $access, StockQuery $stock, ReferenceCodes $codes, ZoneOrganisation $organisation, StockLimits $limits) {
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
            ->groupBy('l.site_id', 'l.zone_id', 'l.waste_type_id', 's.ulid', 's.name', 'z.ulid', 'z.name', 'z.capacity_kg', 'z.capacity_m3', 'z.space_method', 'z.capacity_alert_pct',
                't.ulid', 't.name', 't.is_hazardous', 't.default_unit_id', 't.density_kg_m3', 't.unit_weight_kg')
            ->orderBy('s.name')->orderBy('z.name')->orderBy('t.name')
            ->get([
                'l.site_id', 'l.zone_id', 'l.waste_type_id', 's.ulid as site', 's.name as site_name', 'z.ulid as zone', 'z.name as zone_name',
                'z.capacity_kg', 'z.capacity_m3', 'z.space_method', 'z.capacity_alert_pct', 't.ulid as waste_type', 't.name as waste_type_name', 't.is_hazardous',
                't.default_unit_id', 't.density_kg_m3', 't.unit_weight_kg',
                DB::raw('COALESCE(SUM(l.net_weight_kg), 0) as quantity_kg'), DB::raw('COUNT(*) as lots'),
                DB::raw('SUM(l.net_weight_kg IS NULL) as unweighed_lots'),
                DB::raw("COALESCE(SUM(CASE WHEN l.status = 'awaiting_pickup' THEN l.net_weight_kg ELSE 0 END), 0) as awaiting_pickup_kg"),
                DB::raw('MIN(l.generated_at) as oldest_lot_at'),
            ]);

        // How full each zone is, counted on everything it holds whatever the filters applied to the rows.
        $fills = $rows->unique('zone_id')->mapWithKeys(fn ($row) => [$row->zone_id => $organisation->fill(
            (int) $row->site_id, (int) $row->zone_id, $row->capacity_kg === null ? null : (string) $row->capacity_kg, (int) $row->capacity_alert_pct,
            $row->capacity_m3 === null ? null : (string) $row->capacity_m3, (string) $row->space_method)]);
        // The weight each site holds: every lot once, whatever the filters and the zones. A limit on the whole site is a
        // stock limit like the others (every zone, every waste type), listed with them.
        $sites = $rows->unique('site_id')->map(fn ($row) => ['site' => $row->site, 'site_name' => $row->site_name] + $organisation->siteLoad((int) $row->site_id))->values();
        $thresholds = StockThreshold::query()->where('is_active', true)->when($siteIds !== null, fn ($query) => $query->whereIn('site_id', $siteIds))
            ->orderBy('id')->get();

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
            'sites' => $sites,
            // Each threshold with the stock it currently measures, whatever the filters applied to the rows.
            'thresholds' => $thresholds->map(function (StockThreshold $threshold) use ($limits) {
                // The limit answered on its own, in its unit. Known quantity above it: exceeded, and the lots that could
                // not be counted are named too. Known quantity below it with such lots: unverifiable, never "respected".
                // A stock limit alerts; it blocks nothing.
                $constraint = $limits->constraint($threshold);
                $mass = Measure::dimension((string) $constraint['unit']) === 'mass';

                return [
                    'ulid' => $threshold->ulid,
                    'unit' => $constraint['unit'],
                    'packaging_type' => $constraint['packaging_type'],
                    'quantity' => (string) $constraint['used'],
                    'max_quantity' => (string) $constraint['max'],
                    // The same figures in kilograms when the limit is a weight; null for any other measure.
                    'quantity_kg' => $mass ? UnitConversion::convert((string) $constraint['used'], $constraint['unit'], 'kg') : null,
                    'max_quantity_kg' => $mass ? UnitConversion::convert((string) $constraint['max'], $constraint['unit'], 'kg') : null,
                    // Lots of the scope the limit could not count in its unit.
                    'unweighed_lots' => $constraint['missing_count'],
                    'alert_level' => $constraint['outcome'] === Constraint::RESPECTED ? null : $constraint['outcome'],
                    'constraint' => $constraint,
                ];
            }),
            'meta' => ['total_kg' => $rows->reduce(fn ($sum, $row) => bcadd($sum, (string) $row->quantity_kg, 3), '0'), 'unweighed_lots' => (int) $rows->sum('unweighed_lots'), 'generated_at' => now()->toIso8601ZuluString()],
        ]);
    })->name('stock.index');

    /*
     * The room one package takes, per packaging, for the company: what a zone measured in volume multiplies the
     * packages by. A packaging without a volume is simply not known: its lots are named, never given a guessed size.
     */
    $packagingVolumes = function (StockQuery $stock) {
        $volumes = DB::table('packaging_volumes')->where('company_id', $stock->companyId())->pluck('volume_m3', 'packaging_type_id');

        return ['data' => DB::table('packaging_types')->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get(['id', 'code'])
            ->map(fn ($row) => ['packaging_type' => $row->code, 'volume_m3' => isset($volumes[$row->id]) ? (string) $volumes[$row->id] : null])];
    };

    Route::get('packaging-volumes', function (Request $request, StockQuery $stock) use ($packagingVolumes) {
        abort_unless($request->user()->can('sites.view') || $request->user()->can('sites.manage') || $request->user()->can('stock.view'), 403);

        return $packagingVolumes($stock);
    })->name('packaging-volumes.index');

    Route::put('packaging-volumes', function (Request $request, ReferenceCodes $codes, StockQuery $stock) use ($allow, $packagingVolumes) {
        $allow($request, 'sites.manage');
        $input = $request->validate([
            'volumes' => ['present', 'array', 'max:100'],
            'volumes.*.packaging_type' => ['required', 'string', 'distinct', Rule::exists('packaging_types', 'code')->where('is_active', true)],
            // Empty = not known any more.
            'volumes.*.volume_m3' => ['nullable', 'decimal:0,4', 'gt:0', 'max:100000'],
        ]);
        $companyId = $stock->companyId();
        DB::transaction(function () use ($input, $codes, $companyId) {
            foreach ($input['volumes'] as $line) {
                $key = ['company_id' => $companyId, 'packaging_type_id' => $codes->id('packaging_types', $line['packaging_type'])];
                if (($line['volume_m3'] ?? null) === null) {
                    DB::table('packaging_volumes')->where($key)->delete();
                } else {
                    DB::table('packaging_volumes')->updateOrInsert($key, ['volume_m3' => $line['volume_m3'], 'updated_at' => now()] + (DB::table('packaging_volumes')->where($key)->exists() ? [] : ['created_at' => now()]));
                }
            }
        });

        // The room taken in every zone measured in volume has just changed.
        app(EvaluateCapacityAlerts::class)->forCompany($companyId);

        return $packagingVolumes($stock);
    })->name('packaging-volumes.update');

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
        // The unit of a limit is what it is about: it does not change. Another unit is another limit.
        $input = $request->validate([
            'max_quantity' => ['required_without:max_quantity_kg', 'decimal:0,3', 'gt:0', $threshold->unit_code === Measure::PACKAGE ? 'integer' : 'numeric'],
            'max_quantity_kg' => ['sometimes', 'prohibits:max_quantity', 'decimal:0,3', 'gt:0', $threshold->unit_code === 'kg' ? 'numeric' : 'prohibited'],
            'warning_pct' => ['required', 'integer', 'between:1,100'],
            'is_active' => ['required', 'boolean'],
        ]);
        $threshold->forceFill(['max_quantity' => $input['max_quantity'] ?? $input['max_quantity_kg'], 'warning_pct' => $input['warning_pct'], 'is_active' => $input['is_active']])->save();
        $evaluate->forSite($threshold->site_id);

        return new StockThresholdResource($threshold->refresh()->load($with));
    })->name('stock-thresholds.update');

    Route::delete('stock-thresholds/{threshold}', function (Request $request, StockThreshold $threshold) use ($allow) {
        $allow($request, 'stock.thresholds.manage', $threshold->site_id);
        $threshold->delete();

        return response()->noContent();
    })->name('stock-thresholds.destroy');
});
