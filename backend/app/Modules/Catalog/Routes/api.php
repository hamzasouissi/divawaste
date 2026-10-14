<?php

use App\Modules\Catalog\Actions\CreateWasteType;
use App\Modules\Catalog\Models\WasteToleranceRule;
use App\Modules\Catalog\Models\WasteType;
use App\Modules\Catalog\Requests\StoreWasteTypeRequest;
use App\Modules\Catalog\Resources\WasteTypeResource;
use App\Modules\Catalog\Support\ColorMath;
use App\Modules\Catalog\Support\Colors;
use App\Modules\Common\Exceptions\BusinessRuleViolation;
use App\Modules\Common\Support\ReferenceCodes;
use App\Modules\Identity\Authorization\AccessResolver;
use App\Modules\Sites\Support\StockQuery;
use App\Modules\Tenancy\Rules\TenantExists;
use App\Modules\Tenancy\TenantContext;
use App\Modules\Waste\Support\ToleranceRules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
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
            // Units a waste is counted in. Metres are for raw materials only.
            'units' => $localized(DB::table('units')->where('dimension', '!=', 'length')->orderBy('id')->get(['code', 'name', 'symbol', 'dimension'])),
            'material_units' => $localized(DB::table('units')->whereIn('code', ['m', 'kg', 'piece'])->orderByRaw("FIELD(code, 'm', 'kg', 'piece')")->get(['code', 'name', 'symbol'])),
            'waste_families' => $active('waste_families', ['code', 'name', 'is_textile'], ['is_textile']),
            // The shared names of waste types: what a provider names when a requirement is about one type, not a family.
            'waste_catalog_items' => $localized(DB::table('waste_catalog_items as i')->join('waste_families as f', 'f.id', '=', 'i.waste_family_id')
                ->where('i.is_active', true)->orderBy('i.sort_order')->get(['i.code', 'i.name', 'f.code as waste_family'])),
            'materials' => $active('materials', ['code', 'name', 'material_class']),
            // The defaults of the platform and the colours this company added.
            'color_families' => $localized(Colors::visibleTo($context->companyId())->where('is_active', true)
                ->orderByRaw('company_id is not null')->orderBy('sort_order')->orderBy('id')->get(['code', 'name', 'hex_color', 'pantone_reference', 'color_system', 'collection'])),
            'treatment_channels' => $active('treatment_channels', ['code', 'name', 'is_valorization', 'is_landfill', 'hierarchy_rank'], ['is_valorization', 'is_landfill']),
            'regulatory_waste_codes' => $localized(DB::table('regulatory_waste_codes')->where('code_system', $codeSystem)->where('is_selectable', true)
                ->orderBy('code')->get(['code', 'label', 'is_hazardous']), ['is_hazardous']),
        ]])->header('Cache-Control', 'private, no-store');
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

    Route::post('waste-types', function (StoreWasteTypeRequest $request, CreateWasteType $action) {
        $wasteType = $action->handle($request->toData(), $request->user());
        // A type counted in pieces is counted, one in litres measured, unless the company says it is only estimated.
        $wasteType->forceFill(['requires_authorization' => $request->boolean('requires_authorization'),
            'measurement_method' => $request->input('measurement_method') ?? WasteType::defaultMethod(app(ReferenceCodes::class)->code('units', $wasteType->default_unit_id))])->save();

        return (new WasteTypeResource($wasteType))->response()->setStatusCode(201);
    })->name('waste-types.store');

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
            'requires_authorization' => ['sometimes', 'boolean'],
            'unit' => ['required', 'string', Rule::exists('units', 'code')->whereNot('dimension', 'length')],
            'measurement_method' => ['sometimes', Rule::in(WasteType::METHODS)],
            'packaging_type' => ['nullable', 'string', $reference('packaging_types')],
            'color_family' => ['nullable', 'string', Colors::rule($wasteType->company_id)],
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
            'requires_authorization' => (bool) ($input['requires_authorization'] ?? $wasteType->requires_authorization),
            'default_unit_id' => $codes->id('units', $input['unit']),
            'measurement_method' => $input['measurement_method'] ?? $wasteType->measurement_method,
            'default_packaging_type_id' => $codes->id('packaging_types', $input['packaging_type'] ?? null),
            'default_color_family_id' => $codes->id('color_families', $input['color_family'] ?? null),
            'default_treatment_channel_id' => $codes->id('treatment_channels', $input['treatment_channel'] ?? null),
            'default_grammage_gsm' => $input['default_grammage_gsm'] ?? null,
            'default_composition' => $composition === [] ? null : $composition,
            'density_kg_m3' => $input['density_kg_m3'] ?? null, 'unit_weight_kg' => $input['unit_weight_kg'] ?? null,
        ])->save();

        return new WasteTypeResource($wasteType);
    })->name('waste-types.update');

    // A hazardous type created before its regulatory code was required. The code is completed once — chosen by someone
    // who knows it, never guessed — and is then frozen like any other. The lots of the type that carry no code yet
    // receive it, and so do the pickup lines no slip carries yet: their slip can then be issued. Slips already issued,
    // signed or archived are not touched.
    Route::put('waste-types/{wasteType}/regulatory-code', function (Request $request, WasteType $wasteType) use ($allow) {
        $allow($request, 'waste_types.manage');
        if (! $wasteType->is_hazardous || $wasteType->regulatory_waste_code_id !== null) {
            throw ValidationException::withMessages(['regulatory_code' => [__($wasteType->is_hazardous ? 'validation.custom.regulatory_code.already_set' : 'validation.custom.regulatory_code.not_hazardous')]]);
        }
        $codeSystem = DB::table('companies as c')->join('countries as k', 'k.id', '=', 'c.country_id')->where('c.id', $wasteType->company_id)->value('k.waste_code_system');
        $code = (string) $request->validate(['regulatory_code' => ['required', 'string',
            Rule::exists('regulatory_waste_codes', 'code')->where('code_system', $codeSystem)->where('is_selectable', true)]])['regulatory_code'];
        $codeId = (int) DB::table('regulatory_waste_codes')->where('code_system', $codeSystem)->where('code', $code)->value('id');

        DB::transaction(function () use ($wasteType, $code, $codeId) {
            $wasteType->forceFill(['regulatory_waste_code_id' => $codeId])->save();
            $lots = DB::table('waste_lots')->where('company_id', $wasteType->company_id)->where('waste_type_id', $wasteType->id)->whereNull('regulatory_waste_code_id')->pluck('id');
            DB::table('waste_lots')->whereIn('id', $lots)->update(['regulatory_waste_code_id' => $codeId, 'updated_at' => now()]);
            $lines = DB::table('pickup_lots')->where('company_id', $wasteType->company_id)->whereIn('waste_lot_id', DB::table('waste_lots')->where('company_id', $wasteType->company_id)->where('waste_type_id', $wasteType->id)->select('id'))
                ->whereNull('regulatory_waste_code_id')->whereNull('hazardous_waste_manifest_id')
                ->update(['regulatory_waste_code_id' => $codeId, 'regulatory_code_snapshot' => $code, 'updated_at' => now()]);
            $wasteType->audit('regulatory_code_completed', ['regulatory_code' => null], ['regulatory_code' => $code, 'lots' => $lots->count(), 'pickup_lines' => $lines]);
        });

        return new WasteTypeResource($wasteType->refresh());
    })->name('waste-types.regulatory-code');

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

    /*
     * Tolerances: the gap accepted between the quantity that left and the one received, as a percentage of the
     * first. A rule is for one waste type, one way of handling the waste, both, or neither (every waste). The most
     * precise one applies; below all of them stands the percentage of the company settings.
     */
    $toleranceData = fn (WasteToleranceRule $rule) => [
        'ulid' => $rule->ulid,
        'waste_type' => $rule->wasteType === null ? null : ['ulid' => $rule->wasteType->ulid, 'code' => $rule->wasteType->code, 'name' => $rule->wasteType->name,
            'unit' => app(ReferenceCodes::class)->code('units', $rule->wasteType->default_unit_id)],
        'treatment_channel' => app(ReferenceCodes::class)->code('treatment_channels', $rule->treatment_channel_id),
        'warning_pct' => $rule->warning_pct, 'blocking_pct' => $rule->blocking_pct, 'direction' => $rule->direction,
        'basis' => $rule->basis, 'reference' => $rule->reference, 'notes' => $rule->notes, 'is_active' => $rule->is_active,
    ];
    $toleranceInput = function (Request $request, ?WasteToleranceRule $existing, ReferenceCodes $codes): array {
        $input = $request->validate([
            'waste_type' => ['nullable', 'string', new TenantExists(WasteType::class)],
            'treatment_channel' => ['nullable', 'string', Rule::exists('treatment_channels', 'code')->where('is_active', true)],
            // Above the first the gap must be explained; above the second it must be accepted by someone authorised.
            'warning_pct' => ['nullable', 'required_without:blocking_pct', 'decimal:0,2', 'gt:0', 'lte:1000'],
            'blocking_pct' => ['nullable', 'decimal:0,2', 'gt:0', 'lte:1000'],
            'direction' => ['required', Rule::in(['both', 'excess', 'shortage'])],
            // A legal requirement is recorded only with the text it comes from.
            'basis' => ['required', Rule::in(['operational', 'legal'])],
            'reference' => ['nullable', 'required_if:basis,legal', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        if (isset($input['warning_pct'], $input['blocking_pct']) && bccomp((string) $input['blocking_pct'], (string) $input['warning_pct'], 2) < 0) {
            throw ValidationException::withMessages(['blocking_pct' => [__('validation.custom.tolerance.order')]]);
        }
        $typeId = isset($input['waste_type']) ? (int) WasteType::query()->where('ulid', strtoupper($input['waste_type']))->value('id') : null;
        $channelId = $codes->id('treatment_channels', $input['treatment_channel'] ?? null);
        if (WasteToleranceRule::query()->when($existing !== null, fn ($q) => $q->whereKeyNot($existing->id))
            ->where('waste_type_scope_key', $typeId ?? 0)->where('channel_scope_key', $channelId ?? 0)->exists()) {
            throw ValidationException::withMessages(['waste_type' => [__('validation.custom.tolerance.duplicate')]]);
        }

        return ['waste_type_id' => $typeId, 'treatment_channel_id' => $channelId, 'warning_pct' => $input['warning_pct'] ?? null,
            'blocking_pct' => $input['blocking_pct'] ?? null, 'direction' => $input['direction'], 'basis' => $input['basis'],
            'reference' => $input['reference'] ?? null, 'notes' => $input['notes'] ?? null, 'is_active' => (bool) ($input['is_active'] ?? $existing->is_active ?? true)];
    };

    Route::get('tolerance-rules', function (Request $request, TenantContext $context, ToleranceRules $rules) use ($allow, $toleranceData) {
        $allow($request, 'waste_types.view');

        return ['data' => WasteToleranceRule::query()->with('wasteType:id,ulid,code,name,default_unit_id')->orderBy('waste_type_scope_key')->orderBy('channel_scope_key')->get()->map($toleranceData),
            // What applies when no rule does: the percentage of the company settings, or the 5 % of the cahier.
            'meta' => ['default' => $rules->companyDefault($context->companyId())]];
    })->name('tolerance-rules.index');

    // Which rule would apply to a waste type handled in a given way: what the screens show before any measure exists.
    Route::get('tolerance-rules/resolve', function (Request $request, TenantContext $context, ToleranceRules $rules, ReferenceCodes $codes) {
        abort_unless($request->user()->can('waste_types.view') || app(AccessResolver::class)->siteIdsFor($request->user(), 'lots.view') !== [], 403);
        $input = $request->validate(['waste_type' => ['required', 'string', new TenantExists(WasteType::class)], 'treatment_channel' => ['nullable', 'string', Rule::exists('treatment_channels', 'code')]]);
        $type = WasteType::query()->where('ulid', strtoupper($input['waste_type']))->firstOrFail(['id', 'default_treatment_channel_id']);

        return ['data' => $rules->resolve($context->companyId(), (int) $type->id, $codes->id('treatment_channels', $input['treatment_channel'] ?? null) ?? $type->default_treatment_channel_id)];
    })->name('tolerance-rules.resolve');

    Route::post('tolerance-rules', function (Request $request, ReferenceCodes $codes) use ($allow, $toleranceData, $toleranceInput) {
        $allow($request, 'waste_types.manage');
        $rule = WasteToleranceRule::query()->create($toleranceInput($request, null, $codes) + ['created_by_user_id' => $request->user()->id]);

        return response()->json(['data' => $toleranceData($rule->load('wasteType:id,ulid,code,name,default_unit_id'))], 201);
    })->name('tolerance-rules.store');

    // Changing a rule changes what happens to the next receptions; the ones already judged keep the rule they were judged with.
    Route::put('tolerance-rules/{toleranceRule}', function (Request $request, WasteToleranceRule $toleranceRule, ReferenceCodes $codes) use ($allow, $toleranceData, $toleranceInput) {
        $allow($request, 'waste_types.manage');
        $toleranceRule->forceFill($toleranceInput($request, $toleranceRule, $codes))->save();

        return ['data' => $toleranceData($toleranceRule->load('wasteType:id,ulid,code,name,default_unit_id'))];
    })->name('tolerance-rules.update');

    Route::delete('tolerance-rules/{toleranceRule}', function (Request $request, WasteToleranceRule $toleranceRule) use ($allow) {
        $allow($request, 'waste_types.manage');
        $toleranceRule->delete();

        return response()->noContent();
    })->name('tolerance-rules.destroy');

    /*
     * Colours work like waste types: the defaults of the platform, plus the ones the company adds for itself.
     * A default colour cannot be changed; a company colour is renamed or withdrawn, and deleted only while nothing
     * names it (lots keep their colour).
     *
     * Beside them, the reference catalogue (rows imported from a colour library): it is searched, never modified,
     * and a company takes a colour out of it into its own list.
     */
    $colorData = fn (stdClass $row) => [
        'code' => $row->code, 'name' => json_decode((string) $row->name, true),
        'color_system' => $row->color_system, 'collection' => $row->collection, 'reference_code' => $row->reference_code,
        'hex_color' => $row->hex_color,
        'rgb' => $row->rgb_r === null ? null : ['r' => (int) $row->rgb_r, 'g' => (int) $row->rgb_g, 'b' => (int) $row->rgb_b],
        // Computed from the screen colour, not measured on a sample.
        'lab' => $row->lab_l === null ? null : ['l' => (float) $row->lab_l, 'a' => (float) $row->lab_a, 'b' => (float) $row->lab_b],
        'pantone_reference' => $row->pantone_reference,
        'source' => $row->source, 'verification_status' => $row->verification_status,
        'is_custom' => $row->company_id !== null, 'is_catalogue' => $row->company_id === null && $row->source === Colors::SOURCE_IMPORT,
        'is_active' => (bool) $row->is_active, 'created_at' => $row->created_at, 'updated_at' => $row->updated_at,
    ];
    $colorRules = [
        'name' => ['required', 'string', 'max:60'],
        // The company's own code for the colour, if it has one.
        'reference_code' => ['nullable', 'string', 'max:60'],
        'hex_color' => ['nullable', 'string', 'max:9'],
        'rgb' => ['nullable', 'array:r,g,b'],
        'rgb.r' => ['required_with:rgb', 'integer', 'between:0,255'], 'rgb.g' => ['required_with:rgb', 'integer', 'between:0,255'], 'rgb.b' => ['required_with:rgb', 'integer', 'between:0,255'],
        // Typed by the company (for example "19-4052 TCX") when the colour is not taken from the catalogue.
        'pantone_reference' => ['nullable', 'string', 'max:40'],
    ];
    // HEX and RGB say the same thing: either is enough, both must agree. Returns the normalised HEX, or null for "no tint".
    $colorTint = function (array $input): ?string {
        $hex = null;
        if (($input['hex_color'] ?? null) !== null) {
            $hex = ColorMath::normalizeHex($input['hex_color']);
            if ($hex === null) {
                throw ValidationException::withMessages(['hex_color' => [__('validation.custom.color.hex')]]);
            }
        }
        if (isset($input['rgb'])) {
            $fromRgb = ColorMath::hex((int) $input['rgb']['r'], (int) $input['rgb']['g'], (int) $input['rgb']['b']);
            if ($hex !== null && $hex !== $fromRgb) {
                throw ValidationException::withMessages(['rgb' => [__('validation.custom.color.rgb_mismatch')]]);
            }
            $hex = $fromRgb;
        }

        return $hex;
    };
    // The same name twice in one list would make the choice ambiguous for the operator.
    $colorNameTaken = fn (int $companyId, string $name, ?int $exceptId = null) => Colors::visibleTo($companyId)->where('is_active', true)
        ->when($exceptId !== null, fn ($query) => $query->where('id', '!=', $exceptId))->get(['name'])
        ->contains(fn (stdClass $row) => in_array(mb_strtolower($name), array_map(mb_strtolower(...), array_values((array) json_decode((string) $row->name, true))), true));
    $colorCodeTaken = fn (int $companyId, string $reference, ?int $exceptId = null) => DB::table('color_families')->where('company_id', $companyId)
        ->where('color_system', 'custom')->where('reference_code', $reference)->when($exceptId !== null, fn ($query) => $query->where('id', '!=', $exceptId))->exists();
    // A code, a name or a HEX value, as people type them: "pantone 19-4052", "warm gray", "#0f4c81", "0F4C".
    $colorSearch = function ($query, string $term): void {
        $term = trim((string) preg_replace('/^pantone\s+/i', '', trim($term)));
        if ($term === '') {
            return;
        }
        $like = fn (string $value) => '%'.addcslashes($value, '%_\\').'%';
        $query->where(function ($q) use ($term, $like) {
            $q->where('reference_code', 'like', $like($term))->orWhere('reference_code', 'like', $like(str_replace(' ', '-', $term)))
                ->orWhere('pantone_reference', 'like', $like($term))->orWhereRaw('LOWER(name) like ?', [$like(mb_strtolower($term))]);
            if (preg_match('/^#?[0-9a-f]{1,6}$/i', $term) === 1) {
                $q->orWhere('hex_color', 'like', '#'.ltrim($term, '#').'%');
            }
        });
    };

    Route::get('colors', function (Request $request, TenantContext $context) use ($allow, $colorData, $colorSearch) {
        $allow($request, 'waste_types.view');

        return response()->json(['data' => Colors::visibleTo($context->companyId())
            ->when($request->string('search')->toString(), $colorSearch)
            ->orderByRaw('company_id is not null')->orderBy('sort_order')->orderBy('id')->get()->map($colorData)]);
    })->name('colors.index');

    // The catalogue is large: it is read page by page, filtered by collection and by what the user types.
    Route::get('colors/catalogue', function (Request $request, TenantContext $context) use ($allow, $colorData, $colorSearch) {
        $allow($request, 'waste_types.view');
        $input = $request->validate(['collection' => ['nullable', 'string', 'max:30'], 'search' => ['nullable', 'string', 'max:60'], 'per_page' => ['nullable', 'integer', 'between:1,120'], 'page' => ['nullable', 'integer', 'min:1']]);
        $page = Colors::catalogue()->where('is_active', true)
            ->when($input['collection'] ?? null, fn ($query, $collection) => $query->where('collection', $collection))
            ->when($input['search'] ?? null, $colorSearch)
            ->orderBy('collection')->orderBy('id')->paginate((int) ($input['per_page'] ?? 60));
        // What the company already took, so the screen can say so.
        $taken = DB::table('color_families')->where('company_id', $context->companyId())->whereIn('catalog_color_id', collect($page->items())->pluck('id'))->pluck('code', 'catalog_color_id');

        return response()->json([
            'data' => collect($page->items())->map(fn (stdClass $row) => $colorData($row) + ['taken_as' => $taken[$row->id] ?? null]),
            'meta' => [
                'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(),
                'collections' => Colors::catalogue()->where('is_active', true)->groupBy('collection')->orderBy('collection')->get(['collection', DB::raw('COUNT(*) as colors')])
                    ->map(fn (stdClass $row) => ['collection' => $row->collection, 'colors' => (int) $row->colors]),
            ],
        ])->header('Cache-Control', 'private, no-store');
    })->name('colors.catalogue');

    // The catalogue colours nearest to a given one. Approximate by nature: a suggestion, never an official equivalence.
    Route::get('colors/match', function (Request $request) use ($allow, $colorData) {
        $allow($request, 'waste_types.view');
        $input = $request->validate(['hex' => ['required', 'string', 'max:9'], 'collection' => ['nullable', 'string', 'max:30'], 'limit' => ['nullable', 'integer', 'between:1,20']]);
        $hex = ColorMath::normalizeHex($input['hex']);
        if ($hex === null) {
            throw ValidationException::withMessages(['hex' => [__('validation.custom.color.hex')]]);
        }
        $target = ColorMath::lab($hex);
        $matches = Colors::catalogue()->where('is_active', true)->whereNotNull('lab_l')
            ->when($input['collection'] ?? null, fn ($query, $collection) => $query->where('collection', $collection))->get()
            ->map(fn (stdClass $row) => [$row, ColorMath::deltaE($target, ['l' => (float) $row->lab_l, 'a' => (float) $row->lab_a, 'b' => (float) $row->lab_b])])
            ->sortBy(fn (array $pair) => $pair[1])->take((int) ($input['limit'] ?? 5))->values();

        return response()->json([
            'data' => $matches->map(fn (array $pair) => $colorData($pair[0]) + ['delta_e' => round($pair[1], 2)]),
            'meta' => ['approximate' => true, 'formula' => 'CIEDE2000', 'hex' => $hex, 'rgb' => ColorMath::rgb($hex), 'lab' => $target],
        ])->header('Cache-Control', 'private, no-store');
    })->name('colors.match');

    // Take a catalogue colour into the company's list. Asking twice gives the same colour back.
    Route::post('colors/catalogue/{code}/take', function (Request $request, string $code, TenantContext $context) use ($allow, $colorData, $colorNameTaken) {
        $allow($request, 'waste_types.manage');
        $source = Colors::catalogue()->where('is_active', true)->where('code', $code)->first();
        abort_if($source === null, 404);
        $input = $request->validate(['name' => ['nullable', 'string', 'max:60']]);

        $mine = DB::table('color_families')->where('company_id', $context->companyId())->where('color_system', $source->color_system)
            ->where('collection', $source->collection)->where('reference_code', $source->reference_code)->first();
        if ($mine !== null) {
            DB::table('color_families')->where('id', $mine->id)->update(['is_active' => true, 'catalog_color_id' => $source->id, 'updated_at' => now()]);

            return response()->json(['data' => $colorData(DB::table('color_families')->where('id', $mine->id)->firstOrFail())]);
        }

        $name = trim($input['name'] ?? '') ?: 'Pantone '.$source->reference_code;
        if ($colorNameTaken($context->companyId(), $name)) {
            if (trim($input['name'] ?? '') !== '') {
                throw ValidationException::withMessages(['name' => [__('validation.custom.color.taken')]]);
            }
            // The same reference exists in several collections: say which one.
            $name = Str::limit($name.' · '.str_replace('_', ' ', (string) $source->collection), 60, '');
        }
        $id = DB::table('color_families')->insertGetId(Colors::derived($source->hex_color) + [
            'company_id' => $context->companyId(), 'code' => 'c_'.Str::lower((string) Str::ulid()),
            'color_system' => $source->color_system, 'collection' => $source->collection, 'reference_code' => $source->reference_code,
            'name' => json_encode(['fr' => $name, 'en' => $name], JSON_UNESCAPED_UNICODE), 'pantone_reference' => $source->pantone_reference,
            'source' => Colors::SOURCE_IMPORT, 'source_name' => $source->source_name, 'verification_status' => $source->verification_status, 'catalog_color_id' => $source->id,
            'sort_order' => 1000, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json(['data' => $colorData(DB::table('color_families')->where('id', $id)->firstOrFail())], 201);
    })->name('colors.take');

    Route::get('colors/{code}', function (Request $request, string $code, TenantContext $context) use ($allow, $colorData) {
        $allow($request, 'waste_types.view');
        // A default, one of the company's, or a catalogue colour; another company's answers "not found".
        $color = DB::table('color_families')->where('code', $code)->where(fn ($query) => $query->whereNull('company_id')->orWhere('company_id', $context->companyId()))->first();
        abort_if($color === null, 404);

        return response()->json(['data' => $colorData($color)]);
    })->name('colors.show');

    Route::post('colors', function (Request $request, TenantContext $context) use ($allow, $colorData, $colorRules, $colorTint, $colorNameTaken, $colorCodeTaken) {
        $allow($request, 'waste_types.manage');
        $input = $request->validate($colorRules);
        $tint = $colorTint($input);
        $name = trim($input['name']);
        if ($colorNameTaken($context->companyId(), $name)) {
            throw ValidationException::withMessages(['name' => [__('validation.custom.color.taken')]]);
        }
        $reference = trim($input['reference_code'] ?? '') ?: null;
        if ($reference !== null && $colorCodeTaken($context->companyId(), $reference)) {
            throw ValidationException::withMessages(['reference_code' => [__('validation.custom.color.code_taken')]]);
        }

        $id = DB::table('color_families')->insertGetId(Colors::derived($tint) + [
            'company_id' => $context->companyId(), 'code' => 'c_'.Str::lower((string) Str::ulid()), 'color_system' => 'custom', 'reference_code' => $reference,
            'name' => json_encode(['fr' => $name, 'en' => $name], JSON_UNESCAPED_UNICODE),
            'pantone_reference' => isset($input['pantone_reference']) ? (trim($input['pantone_reference']) ?: null) : null,
            'source' => Colors::SOURCE_USER, 'sort_order' => 1000, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json(['data' => $colorData(DB::table('color_families')->where('id', $id)->firstOrFail())], 201);
    })->name('colors.store');

    Route::put('colors/{code}', function (Request $request, string $code, TenantContext $context) use ($allow, $colorData, $colorRules, $colorTint, $colorNameTaken, $colorCodeTaken) {
        $allow($request, 'waste_types.manage');
        // Only the company's own colours: a default one, a catalogue one, or another company's, answers "not found".
        $color = DB::table('color_families')->where('code', $code)->where('company_id', $context->companyId())->first();
        abort_if($color === null, 404);
        $input = $request->validate($colorRules + ['is_active' => ['sometimes', 'boolean']]);
        $tint = $color->color_system === 'custom' ? $colorTint($input) : null;
        $name = trim($input['name']);
        if (($input['is_active'] ?? true) && $colorNameTaken($context->companyId(), $name, (int) $color->id)) {
            throw ValidationException::withMessages(['name' => [__('validation.custom.color.taken')]]);
        }

        $values = ['name' => json_encode(['fr' => $name, 'en' => $name], JSON_UNESCAPED_UNICODE), 'is_active' => (bool) ($input['is_active'] ?? $color->is_active), 'updated_at' => now()];
        // A colour taken from the catalogue keeps the reference and the tint of the catalogue: only its name is the company's.
        if ($color->color_system === 'custom') {
            $reference = trim($input['reference_code'] ?? '') ?: null;
            if ($reference !== null && $colorCodeTaken($context->companyId(), $reference, (int) $color->id)) {
                throw ValidationException::withMessages(['reference_code' => [__('validation.custom.color.code_taken')]]);
            }
            $values += Colors::derived($tint) + [
                'reference_code' => $reference, 'pantone_reference' => isset($input['pantone_reference']) ? (trim($input['pantone_reference']) ?: null) : null,
            ];
        }
        DB::table('color_families')->where('id', $color->id)->update($values);

        return response()->json(['data' => $colorData(DB::table('color_families')->where('id', $color->id)->firstOrFail())]);
    })->name('colors.update');

    // A colour nothing names can be removed; one that a lot, a type, a material or a zone carries is withdrawn instead.
    Route::delete('colors/{code}', function (Request $request, string $code, TenantContext $context) use ($allow) {
        $allow($request, 'waste_types.manage');
        $color = DB::table('color_families')->where('code', $code)->where('company_id', $context->companyId())->first();
        abort_if($color === null, 404);
        if (Colors::inUse((int) $color->id)) {
            throw new BusinessRuleViolation('COLOR_IN_USE', 'This colour is used by lots, waste types, materials or zones. Withdraw it instead.');
        }
        DB::table('color_families')->where('id', $color->id)->delete();

        return response()->noContent();
    })->name('colors.destroy');
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
