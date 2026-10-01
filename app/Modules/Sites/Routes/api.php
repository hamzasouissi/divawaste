<?php

use App\Modules\Identity\Authorization\AccessResolver;
use App\Modules\Sites\Actions\CreateSite;
use App\Modules\Sites\Models\Site;
use App\Modules\Sites\Requests\StoreSiteRequest;
use App\Modules\Sites\Resources\SiteResource;
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
});
