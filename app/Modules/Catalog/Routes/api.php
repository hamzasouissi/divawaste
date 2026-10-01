<?php

use App\Modules\Catalog\Actions\CreateWasteType;
use App\Modules\Catalog\Models\WasteType;
use App\Modules\Catalog\Requests\StoreWasteTypeRequest;
use App\Modules\Catalog\Resources\WasteTypeResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'tenant'])->group(function () {
    Route::get('waste-types', function (Request $request) {
        abort_unless($request->user()->can('waste_types.view'), 403);

        return WasteTypeResource::collection(WasteType::query()
            ->select(['id', 'ulid', 'code', 'name', 'description', 'is_hazardous', 'default_grammage_gsm', 'default_composition',
                'density_kg_m3', 'unit_weight_kg', 'extra_attributes', 'is_active'])
            ->when($request->boolean('active_only', true), fn ($query) => $query->where('is_active', true))
            ->orderBy('code')
            ->paginate(min((int) $request->integer('per_page', 25), 100)));
    })->name('waste-types.index');

    Route::post('waste-types', fn (StoreWasteTypeRequest $request, CreateWasteType $action) => (new WasteTypeResource(
        $action->handle($request->toData(), $request->user())
    ))->response()->setStatusCode(201))->name('waste-types.store');
});
