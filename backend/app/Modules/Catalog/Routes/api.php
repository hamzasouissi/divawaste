<?php

use App\Modules\Catalog\Actions\CreateWasteType;
use App\Modules\Catalog\Models\WasteType;
use App\Modules\Catalog\Requests\StoreWasteTypeRequest;
use App\Modules\Catalog\Resources\WasteTypeResource;
use App\Modules\Common\Exceptions\BusinessRuleViolation;
use App\Modules\Common\Support\ReferenceCodes;
use App\Modules\Identity\Authorization\AccessResolver;
use App\Modules\Sites\Support\StockQuery;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

$localized = fn ($rows, array $booleans = []) => $rows->map(function (object $row) use ($booleans) {
    $data = (array) $row;
    foreach (['name', 'label', 'description'] as $key) {
        if (array_key_exists($key, $data) && is_string($data[$key])) {
            $data[$key] = json_decode($data[$key], true);
        }
    }
    foreach ($booleans as $key) {
        $data[$key] = (bool) $data[$key];
    }

    return $data;
})->values();

Route::middleware(['auth:sanctum', 'tenant:active,pending_approval'])->group(function () use ($localized) {
    /*
     * Vocabularies used by every form (zone types, packaging, units, materials, colours, channels, waste codes of
     * the company's country). Names come in every available language; the client picks the user's.
     */
    Route::get('reference', function (TenantContext $context) use ($localized) {
        $active = fn (string $table, array $columns, array $booleans = []) => $localized(
            DB::table($table)->where('is_active', true)->orderBy('sort_order')->get($columns), $booleans);
        $codeSystem = DB::table('companies as c')->join('countries as k', 'k.id', '=', 'c.country_id')->where('c.id', $context->companyId())->value('k.waste_code_system');

        return response()->json(['data' => [
            'zone_types' => $active('zone_types', ['code', 'name', 'is_waste_storage'], ['is_waste_storage']),
            'packaging_types' => $active('packaging_types', ['code', 'name', 'default_tare_kg']),
            'units' => $localized(DB::table('units')->orderBy('id')->get(['code', 'name', 'symbol', 'dimension'])),
            'waste_families' => $active('waste_families', ['code', 'name', 'is_textile'], ['is_textile']),
            'materials' => $active('materials', ['code', 'name', 'material_class']),
            'color_families' => $active('color_families', ['code', 'name', 'hex_color']),
            'treatment_channels' => $active('treatment_channels', ['code', 'name', 'is_valorization', 'is_landfill', 'hierarchy_rank'], ['is_valorization', 'is_landfill']),
            'regulatory_waste_codes' => $localized(DB::table('regulatory_waste_codes')->where('code_system', $codeSystem)->where('is_selectable', true)
                ->orderBy('code')->get(['code', 'label', 'is_hazardous']), ['is_hazardous']),
        ]])->header('Cache-Control', 'private, max-age=300');
    })->name('reference.index');
});

Route::middleware(['auth:sanctum', 'tenant'])->group(function () use ($localized) {
    $allow = fn (Request $request, string $permission) => abort_unless($request->user()->can($permission), 403);

    // M1-02: the reference catalogue, with what this company has already activated.
    Route::get('waste-catalog', function (Request $request, TenantContext $context, ReferenceCodes $codes) use ($allow, $localized) {
        $allow($request, 'waste_types.view');
        $activated = DB::table('waste_types')->where('company_id', $context->companyId())->whereNull('deleted_at')
            ->whereNotNull('waste_catalog_item_id')->pluck('ulid', 'waste_catalog_item_id');

        return ['data' => $localized(DB::table('waste_catalog_items')->where('is_active', true)->orderBy('sort_order')
            ->get(['id', 'code', 'name', 'description', 'waste_family_id', 'default_regulatory_waste_code_id', 'default_is_hazardous', 'default_treatment_channel_id']))
            ->map(fn (array $item) => [
                'code' => $item['code'], 'name' => $item['name'], 'description' => $item['description'],
                'waste_family' => $codes->code('waste_families', $item['waste_family_id']),
                'regulatory_code' => $codes->code('regulatory_waste_codes', $item['default_regulatory_waste_code_id']),
                'is_hazardous' => (bool) $item['default_is_hazardous'],
                'treatment_channel' => $codes->code('treatment_channels', $item['default_treatment_channel_id']),
                'waste_type' => $activated[$item['id']] ?? null,
            ])];
    })->name('waste-catalog.index');

    // Whoever may read lots may read the names of their types (list filters, lot forms): it reveals nothing more.
    Route::get('waste-types', function (Request $request) {
        abort_unless($request->user()->can('waste_types.view') || app(AccessResolver::class)->siteIdsFor($request->user(), 'lots.view') !== [], 403);

        return WasteTypeResource::collection(WasteType::query()
            ->when($request->boolean('active_only', true), fn ($query) => $query->where('is_active', true))
            ->when($request->has('hazardous'), fn ($query) => $query->where('is_hazardous', $request->boolean('hazardous')))
            ->when($request->string('search')->toString(), fn ($query, $value) => $query->where(fn ($q) => $q
                ->where('name', 'like', '%'.addcslashes($value, '%_\\').'%')->orWhere('code', 'like', addcslashes($value, '%_\\').'%')))
            ->orderBy('code')
            ->paginate(min((int) $request->integer('per_page', 25), 100)));
    })->name('waste-types.index');

    Route::post('waste-types', fn (StoreWasteTypeRequest $request, CreateWasteType $action) => (new WasteTypeResource(
        $action->handle($request->toData(), $request->user())
    ))->response()->setStatusCode(201))->name('waste-types.store');

    Route::get('waste-types/{wasteType}', function (Request $request, WasteType $wasteType) use ($allow) {
        $allow($request, 'waste_types.view');

        return new WasteTypeResource($wasteType);
    })->name('waste-types.show');

    // Code, family, regulatory code and hazard status are frozen once created: existing lots carry them as snapshots
    // and regulatory documents rely on them. A different classification is a new type.
    Route::put('waste-types/{wasteType}', function (Request $request, WasteType $wasteType, ReferenceCodes $codes) use ($allow) {
        $allow($request, 'waste_types.manage');
        $reference = fn (string $table) => Rule::exists($table, 'code')->where('is_active', true);
        $input = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:1000'],
            'unit' => ['required', 'string', Rule::exists('units', 'code')],
            'packaging_type' => ['nullable', 'string', $reference('packaging_types')],
            'color_family' => ['nullable', 'string', $reference('color_families')],
            'treatment_channel' => ['nullable', 'string', $reference('treatment_channels')],
            'default_grammage_gsm' => ['nullable', 'decimal:0,2', 'gt:0'],
            'default_composition' => ['nullable', 'array'],
            'default_composition.*.material' => ['required', 'string', 'distinct', $reference('materials')],
            'default_composition.*.pct' => ['required', 'decimal:0,2', 'gt:0', 'lte:100'],
            'density_kg_m3' => ['nullable', 'decimal:0,3', 'gt:0'],
            'unit_weight_kg' => ['nullable', 'decimal:0,3', 'gt:0'],
        ]);
        $composition = $input['default_composition'] ?? null;
        if ($composition !== null && $composition !== []
            && bccomp(array_reduce($composition, fn (string $sum, array $line) => bcadd($sum, (string) $line['pct'], 2), '0'), '100', 2) !== 0) {
            throw ValidationException::withMessages(['default_composition' => [__('validation.custom.composition.total')]]);
        }

        $wasteType->forceFill([
            'name' => $input['name'], 'description' => $input['description'] ?? null,
            'default_unit_id' => $codes->id('units', $input['unit']),
            'default_packaging_type_id' => $codes->id('packaging_types', $input['packaging_type'] ?? null),
            'default_color_family_id' => $codes->id('color_families', $input['color_family'] ?? null),
            'default_treatment_channel_id' => $codes->id('treatment_channels', $input['treatment_channel'] ?? null),
            'default_grammage_gsm' => $input['default_grammage_gsm'] ?? null,
            'default_composition' => $composition === [] ? null : $composition,
            'density_kg_m3' => $input['density_kg_m3'] ?? null, 'unit_weight_kg' => $input['unit_weight_kg'] ?? null,
        ])->save();

        return new WasteTypeResource($wasteType);
    })->name('waste-types.update');

    // Deactivated types remain on existing lots and documents; they simply stop being offered for new lots.
    Route::post('waste-types/{wasteType}/deactivate', function (Request $request, WasteType $wasteType, StockQuery $stock) use ($allow) {
        $allow($request, 'waste_types.manage');
        if ($stock->lots()->where('l.waste_type_id', $wasteType->id)->exists()) {
            throw new BusinessRuleViolation('WASTE_TYPE_HAS_STOCK', 'Lots of this type are still on site.');
        }
        $wasteType->forceFill(['is_active' => false])->save();

        return new WasteTypeResource($wasteType);
    })->name('waste-types.deactivate');

    Route::post('waste-types/{wasteType}/activate', function (Request $request, WasteType $wasteType) use ($allow) {
        $allow($request, 'waste_types.manage');
        $wasteType->forceFill(['is_active' => true])->save();

        return new WasteTypeResource($wasteType);
    })->name('waste-types.activate');
});

/*
 * Platform: the reference catalogue every client starts from (M1-02).
 */
Route::middleware('auth:sanctum')->prefix('platform')->group(function () use ($localized) {
    $allow = fn (Request $request) => abort_unless($request->user()->hasPermissionTo('platform.catalog.manage'), 403);
    $rules = fn (?int $ignoreId = null) => [
        'code' => [$ignoreId === null ? 'required' : 'exclude', 'string', 'max:40', 'regex:/^[a-z][a-z0-9_]*$/', Rule::unique('waste_catalog_items', 'code')],
        'name_fr' => ['required', 'string', 'max:150'],
        'name_en' => ['nullable', 'string', 'max:150'],
        'waste_family' => ['required', 'string', Rule::exists('waste_families', 'code')],
        'regulatory_code' => ['nullable', 'string', Rule::exists('regulatory_waste_codes', 'code')],
        'is_hazardous' => ['required', 'boolean'],
        'treatment_channel' => ['nullable', 'string', Rule::exists('treatment_channels', 'code')],
        'is_active' => ['sometimes', 'boolean'],
    ];
    $attributes = fn (array $input, ReferenceCodes $codes) => [
        'name' => json_encode(['fr' => $input['name_fr'], 'en' => $input['name_en'] ?? $input['name_fr']], JSON_UNESCAPED_UNICODE),
        'waste_family_id' => $codes->id('waste_families', $input['waste_family']),
        'default_regulatory_waste_code_id' => isset($input['regulatory_code']) ? DB::table('regulatory_waste_codes')->where('code', $input['regulatory_code'])->value('id') : null,
        'default_is_hazardous' => (bool) $input['is_hazardous'],
        'default_treatment_channel_id' => $codes->id('treatment_channels', $input['treatment_channel'] ?? null),
        'is_active' => (bool) ($input['is_active'] ?? true),
        'updated_at' => now(),
    ];

    Route::get('waste-catalog', function (Request $request, ReferenceCodes $codes) use ($allow, $localized) {
        $allow($request);

        return ['data' => $localized(DB::table('waste_catalog_items')->orderBy('sort_order')->get())->map(fn (array $item) => [
            'code' => $item['code'], 'name' => $item['name'], 'waste_family' => $codes->code('waste_families', $item['waste_family_id']),
            'regulatory_code' => $codes->code('regulatory_waste_codes', $item['default_regulatory_waste_code_id']),
            'is_hazardous' => (bool) $item['default_is_hazardous'],
            'treatment_channel' => $codes->code('treatment_channels', $item['default_treatment_channel_id']),
            'is_active' => (bool) $item['is_active'],
            'used_by' => DB::table('waste_types')->where('waste_catalog_item_id', $item['id'])->distinct()->count('company_id'),
        ]),
            // The platform has no company, hence no /reference: the vocabularies its form needs come with the list.
            'meta' => [
                'waste_families' => $localized(DB::table('waste_families')->where('is_active', true)->orderBy('sort_order')->get(['code', 'name'])),
                'treatment_channels' => $localized(DB::table('treatment_channels')->where('is_active', true)->orderBy('sort_order')->get(['code', 'name'])),
                'regulatory_waste_codes' => $localized(DB::table('regulatory_waste_codes')->where('is_selectable', true)->orderBy('code')
                    ->get(['code', 'label', 'is_hazardous']), ['is_hazardous']),
            ]];
    })->name('platform.waste-catalog.index');

    Route::post('waste-catalog', function (Request $request, ReferenceCodes $codes) use ($allow, $rules, $attributes) {
        $allow($request);
        $input = $request->validate($rules());
        DB::table('waste_catalog_items')->insert($attributes($input, $codes) + [
            'code' => $input['code'], 'default_unit_id' => $codes->id('units', 'kg'),
            'sort_order' => (int) DB::table('waste_catalog_items')->max('sort_order') + 10, 'created_at' => now(),
        ]);

        return response()->json(['data' => ['code' => $input['code']]], 201);
    })->name('platform.waste-catalog.store');

    Route::put('waste-catalog/{code}', function (Request $request, string $code, ReferenceCodes $codes) use ($allow, $rules, $attributes) {
        $allow($request);
        $id = DB::table('waste_catalog_items')->where('code', $code)->value('id');
        abort_if($id === null, 404);
        DB::table('waste_catalog_items')->where('id', $id)->update($attributes($request->validate($rules((int) $id)), $codes));

        return response()->noContent();
    })->name('platform.waste-catalog.update');
});
