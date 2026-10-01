<?php

use App\Modules\Identity\Authorization\AccessResolver;
use App\Modules\Sites\Actions\CreateSite;
use App\Modules\Sites\Actions\CreateStockThreshold;
use App\Modules\Sites\Models\Site;
use App\Modules\Sites\Models\StockThreshold;
use App\Modules\Sites\Requests\StoreSiteRequest;
use App\Modules\Sites\Requests\StoreStockThresholdRequest;
use App\Modules\Sites\Resources\SiteResource;
use App\Modules\Sites\Resources\StockThresholdResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'tenant'])->group(function () {
    Route::get('sites', function (Request $request, AccessResolver $access) {
        $siteIds = $access->siteIdsFor($request->user(), 'sites.view');

        return SiteResource::collection(Site::query()
            ->select(['id', 'ulid', 'code', 'name', 'site_kind', 'city', 'region', 'timezone', 'capacity_kg', 'is_active'])
            ->when($siteIds !== null, fn ($query) => $query->whereIn('id', $siteIds))
            ->orderBy('code')
            ->paginate(min((int) $request->integer('per_page', 25), 100)));
    })->name('sites.index');

    Route::post('sites', fn (StoreSiteRequest $request, CreateSite $action) => (new SiteResource(
        $action->handle($request->toData(), $request->user())
    ))->response()->setStatusCode(201))->name('sites.store');

    Route::get('stock-thresholds', function (Request $request, AccessResolver $access) {
        $siteIds = $access->siteIdsFor($request->user(), 'stock.view');

        return StockThresholdResource::collection(StockThreshold::query()
            ->select(['id', 'ulid', 'site_id', 'zone_id', 'waste_type_id', 'max_quantity_kg', 'warning_pct', 'alert_level', 'alerted_at', 'is_active'])
            ->with(['site:id,ulid', 'zone:id,ulid', 'wasteType:id,ulid'])
            ->when($siteIds !== null, fn ($query) => $query->whereIn('site_id', $siteIds))
            ->orderBy('id')
            ->paginate(min((int) $request->integer('per_page', 25), 100)));
    })->name('stock-thresholds.index');

    Route::post('stock-thresholds', fn (StoreStockThresholdRequest $request, CreateStockThreshold $action) => (new StockThresholdResource(
        $action->handle($request->toData(), $request->user())->load(['site:id,ulid', 'zone:id,ulid', 'wasteType:id,ulid'])
    ))->response()->setStatusCode(201))->name('stock-thresholds.store');
});
