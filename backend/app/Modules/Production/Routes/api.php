<?php

use App\Modules\Catalog\Support\Colors;
use App\Modules\Common\Exceptions\BusinessRuleViolation;
use App\Modules\Common\Support\ReferenceCodes;
use App\Modules\Production\Models\Article;
use App\Modules\Production\Models\CustomerOrder;
use App\Modules\Production\Models\ProductionClient;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Production\Models\ProductionSupplier;
use App\Modules\Production\Models\RawMaterial;
use App\Modules\Production\Support\ImportEngine;
use App\Modules\Production\Support\ProductionData;
use App\Modules\Reporting\Support\ArrayExport;
use App\Modules\Sites\Models\Site;
use App\Modules\Tenancy\Rules\TenantExists;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;

/*
 * Production reference: clients, suppliers, raw materials, articles, orders and manufacturing orders (OF).
 * Just enough to say where a waste comes from; everything here is optional for a company. Nothing is deleted: what
 * is no longer used is deactivated (parties, materials, articles) or closed (orders), because lots point to it.
 */
Route::middleware(['auth:sanctum', 'tenant'])->prefix('production')->group(function () {
    $view = fn (Request $request) => abort_unless($request->user()->can('production.view'), 403);
    $manage = fn (Request $request) => abort_unless($request->user()->can('production.manage'), 403);
    $like = fn (string $value) => '%'.addcslashes($value, '%_\\').'%';
    $page = fn (Request $request) => min((int) $request->integer('per_page', 25), 200);
    $id = fn (string $model, ?string $ulid) => $ulid === null ? null : (int) $model::query()->where('ulid', strtoupper($ulid))->value('id');
    /** One answer shape for every list: rows, then the pagination the tables of the application expect. */
    $paged = fn ($paginator, callable $row) => response()->json(['data' => collect($paginator->items())->map($row)->values(),
        'meta' => ['current_page' => $paginator->currentPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total(), 'last_page' => $paginator->lastPage()]]);

    /*
     * Clients and suppliers: the same card.
     */
    foreach (['clients' => ProductionClient::class, 'suppliers' => ProductionSupplier::class] as $path => $model) {
        $table = (new $model)->getTable();
        $row = fn (Model $party) => $party->only(['ulid', 'code', 'name', 'country_code', 'contact_name', 'email', 'phone', 'notes', 'is_active']);
        $rules = fn (?Model $party = null) => [
            'code' => ['required', 'string', 'max:40', Rule::unique($table, 'code')->where('company_id', app(TenantContext::class)->companyId())->ignore($party?->getKey())],
            'name' => ['required', 'string', 'max:191'],
            'country_code' => ['nullable', 'string', 'size:2'],
            'contact_name' => ['nullable', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:191'],
            'phone' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
        ];

        Route::get($path, function (Request $request) use ($view, $model, $row, $like, $page, $paged) {
            $view($request);

            return $paged($model::query()
                ->when(! $request->boolean('with_inactive'), fn (Builder $query) => $query->where('is_active', true))
                ->when($request->string('search')->toString(), fn (Builder $query, string $value) => $query->where(fn (Builder $q) => $q->where('name', 'like', $like($value))->orWhere('code', 'like', $like($value))))
                ->orderBy('name')->paginate($page($request)), $row);
        })->name("production.{$path}.index");

        Route::post($path, function (Request $request) use ($manage, $model, $row, $rules) {
            $manage($request);

            return response()->json(['data' => $row($model::query()->create($request->validate($rules())))], 201);
        })->name("production.{$path}.store");

        Route::put("{$path}/{party}", function (Request $request, string $party) use ($manage, $model, $row, $rules) {
            $manage($request);
            $party = $model::query()->where('ulid', strtoupper($party))->firstOrFail();
            $party->update($request->validate($rules($party)));

            return response()->json(['data' => $row($party)]);
        })->name("production.{$path}.update");
    }

    /*
     * Stages of production: the defaults of the platform, plus the ones the company adds (like colours).
     */
    $stepRow = fn (stdClass $step) => ['code' => $step->code, 'name' => json_decode((string) $step->name, true), 'is_custom' => $step->company_id !== null, 'is_active' => (bool) $step->is_active];

    Route::get('steps', function (Request $request, TenantContext $context) use ($stepRow) {
        abort_unless($request->user()->can('production.view') || $request->user()->can('lots.create'), 403);

        return ['data' => ProductionData::steps($context->companyId())->orderByRaw('company_id is not null')->orderBy('sort_order')->orderBy('id')->get()->map($stepRow)];
    })->name('production.steps.index');

    Route::post('steps', function (Request $request, TenantContext $context) use ($manage, $stepRow) {
        $manage($request);
        $name = trim((string) $request->validate(['name' => ['required', 'string', 'max:60']])['name']);
        $id = DB::table('production_steps')->insertGetId(['company_id' => $context->companyId(), 'code' => 's_'.Str::lower((string) Str::ulid()),
            'name' => json_encode(['fr' => $name, 'en' => $name], JSON_UNESCAPED_UNICODE), 'sort_order' => 1000, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

        return response()->json(['data' => $stepRow(DB::table('production_steps')->where('id', $id)->firstOrFail())], 201);
    })->name('production.steps.store');

    Route::put('steps/{code}', function (Request $request, string $code, TenantContext $context) use ($manage, $stepRow) {
        $manage($request);
        // Only the company's own stages: a default one, or another company's, answers "not found".
        $step = DB::table('production_steps')->where('code', $code)->where('company_id', $context->companyId())->first();
        abort_if($step === null, 404);
        $input = $request->validate(['name' => ['required', 'string', 'max:60'], 'is_active' => ['sometimes', 'boolean']]);
        DB::table('production_steps')->where('id', $step->id)->update(['name' => json_encode(['fr' => trim($input['name']), 'en' => trim($input['name'])], JSON_UNESCAPED_UNICODE),
            'is_active' => (bool) ($input['is_active'] ?? $step->is_active), 'updated_at' => now()]);

        return response()->json(['data' => $stepRow(DB::table('production_steps')->where('id', $step->id)->firstOrFail())]);
    })->name('production.steps.update');

    /*
     * Raw materials and what was received of them.
     */
    $materialRules = fn (?RawMaterial $material = null) => [
        'reference' => ['required', 'string', 'max:60', Rule::unique('raw_materials', 'reference')->where('company_id', app(TenantContext::class)->companyId())->ignore($material?->id)],
        'name' => ['required', 'string', 'max:191'],
        'supplier' => ['nullable', 'string', new TenantExists(ProductionSupplier::class)],
        'unit' => ['required', 'string', Rule::in(ProductionData::MATERIAL_UNITS)],
        'color_family' => ['nullable', 'string', Colors::rule(app(TenantContext::class)->companyId())],
        'color_label' => ['nullable', 'string', 'max:100'],
        'grammage_gsm' => ['nullable', 'decimal:0,2', 'gt:0'],
        'composition' => ['nullable', 'array'],
        'composition.*.material' => ['required', 'string', 'distinct', Rule::exists('materials', 'code')->where('is_active', true)],
        'composition.*.pct' => ['required', 'decimal:0,2', 'gt:0', 'lte:100'],
        'notes' => ['nullable', 'string', 'max:1000'],
        'is_active' => ['sometimes', 'boolean'],
    ];
    $materialAttributes = function (array $input, ReferenceCodes $codes) use ($id) {
        $composition = $input['composition'] ?? [];
        if ($composition !== [] && bccomp(array_reduce($composition, fn (string $sum, array $line) => bcadd($sum, (string) $line['pct'], 2), '0'), '100', 2) !== 0) {
            throw ValidationException::withMessages(['composition' => [__('validation.custom.composition.total')]]);
        }

        return [
            'reference' => trim($input['reference']), 'name' => trim($input['name']), 'production_supplier_id' => $id(ProductionSupplier::class, $input['supplier'] ?? null),
            'unit_id' => $codes->id('units', $input['unit']), 'color_family_id' => $codes->id('color_families', $input['color_family'] ?? null),
            'color_label' => $input['color_label'] ?? null, 'grammage_gsm' => $input['grammage_gsm'] ?? null, 'composition' => $composition === [] ? null : $composition,
            'notes' => $input['notes'] ?? null, 'is_active' => (bool) ($input['is_active'] ?? true),
        ];
    };

    Route::get('materials', function (Request $request, ProductionData $data) use ($view, $like, $page, $paged) {
        $view($request);

        return $paged(RawMaterial::query()
            ->when(! $request->boolean('with_inactive'), fn (Builder $query) => $query->where('is_active', true))
            ->when($request->string('search')->toString(), fn (Builder $query, string $value) => $query->where(fn (Builder $q) => $q->where('name', 'like', $like($value))->orWhere('reference', 'like', $like($value))))
            ->when($request->string('supplier')->toString(), fn (Builder $query, string $value) => $query->whereIn('production_supplier_id', ProductionSupplier::query()->select('id')->where('ulid', strtoupper($value))))
            ->orderBy('reference')->paginate($page($request)), fn (RawMaterial $material) => $data->material($material));
    })->name('production.materials.index');

    Route::post('materials', function (Request $request, ProductionData $data, ReferenceCodes $codes) use ($manage, $materialRules, $materialAttributes) {
        $manage($request);

        return response()->json(['data' => $data->material(RawMaterial::query()->create($materialAttributes($request->validate($materialRules()), $codes)))], 201);
    })->name('production.materials.store');

    Route::put('materials/{material}', function (Request $request, RawMaterial $material, ProductionData $data, ReferenceCodes $codes) use ($manage, $materialRules, $materialAttributes) {
        $manage($request);
        $material->update($materialAttributes($request->validate($materialRules($material)), $codes));

        return response()->json(['data' => $data->material($material)]);
    })->name('production.materials.update');

    // A delivery of the material. Its number cannot change once a consumption or a lot points to it; here it is only added.
    Route::post('materials/{material}/batches', function (Request $request, RawMaterial $material, ProductionData $data) use ($manage) {
        $manage($request);
        $input = $request->validate([
            'batch_number' => ['required', 'string', 'max:60', Rule::unique('raw_material_batches', 'batch_number')->where('raw_material_id', $material->id)],
            'received_on' => ['nullable', 'date'],
            'quantity' => ['nullable', 'decimal:0,3', 'gt:0'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        DB::table('raw_material_batches')->insert($input + ['ulid' => strtoupper((string) Str::ulid()), 'company_id' => $material->company_id, 'raw_material_id' => $material->id, 'created_at' => now(), 'updated_at' => now()]);

        return response()->json(['data' => $data->material($material)], 201);
    })->name('production.materials.batches.store');

    /*
     * Articles, with what one piece is expected to consume.
     */
    $articleRules = fn (?Article $article = null) => [
        'reference' => ['required', 'string', 'max:60', Rule::unique('articles', 'reference')->where('company_id', app(TenantContext::class)->companyId())->ignore($article?->id)],
        'name' => ['required', 'string', 'max:191'],
        'family' => ['nullable', 'string', 'max:80'],
        'client' => ['nullable', 'string', new TenantExists(ProductionClient::class)],
        'notes' => ['nullable', 'string', 'max:1000'],
        'is_active' => ['sometimes', 'boolean'],
        'materials' => ['sometimes', 'array', 'max:50'],
        'materials.*.material' => ['required', 'string', 'distinct', new TenantExists(RawMaterial::class)],
        'materials.*.quantity_per_piece' => ['required', 'decimal:0,4', 'gt:0'],
    ];
    $saveArticle = function (Article $article, array $input) use ($id) {
        return DB::transaction(function () use ($article, $input, $id) {
            $article->fill(['reference' => trim($input['reference']), 'name' => trim($input['name']), 'family' => $input['family'] ?? null,
                'production_client_id' => $id(ProductionClient::class, $input['client'] ?? null), 'notes' => $input['notes'] ?? null, 'is_active' => (bool) ($input['is_active'] ?? true)])->save();
            if (array_key_exists('materials', $input)) {
                DB::table('article_materials')->where('article_id', $article->id)->delete();
                DB::table('article_materials')->insert(array_map(fn (array $line) => ['company_id' => $article->company_id, 'article_id' => $article->id,
                    'raw_material_id' => $id(RawMaterial::class, $line['material']), 'quantity_per_piece' => $line['quantity_per_piece'], 'created_at' => now(), 'updated_at' => now()], $input['materials']));
            }

            return $article;
        });
    };

    Route::get('articles', function (Request $request, ProductionData $data) use ($view, $like, $page, $paged) {
        $view($request);

        return $paged(Article::query()
            ->when(! $request->boolean('with_inactive'), fn (Builder $query) => $query->where('is_active', true))
            ->when($request->string('search')->toString(), fn (Builder $query, string $value) => $query->where(fn (Builder $q) => $q->where('name', 'like', $like($value))->orWhere('reference', 'like', $like($value))))
            ->when($request->string('client')->toString(), fn (Builder $query, string $value) => $query->whereIn('production_client_id', ProductionClient::query()->select('id')->where('ulid', strtoupper($value))))
            ->orderBy('reference')->paginate($page($request)), fn (Article $article) => $data->article($article));
    })->name('production.articles.index');

    Route::post('articles', function (Request $request, ProductionData $data) use ($manage, $articleRules, $saveArticle) {
        $manage($request);

        return response()->json(['data' => $data->article($saveArticle(new Article, $request->validate($articleRules())))], 201);
    })->name('production.articles.store');

    Route::put('articles/{article}', function (Request $request, Article $article, ProductionData $data) use ($manage, $articleRules, $saveArticle) {
        $manage($request);

        return response()->json(['data' => $data->article($saveArticle($article, $request->validate($articleRules($article))))]);
    })->name('production.articles.update');

    /*
     * Orders of the clients.
     */
    $orderRules = fn (?CustomerOrder $order = null) => [
        'order_number' => ['required', 'string', 'max:60', Rule::unique('customer_orders', 'order_number')->where('company_id', app(TenantContext::class)->companyId())->ignore($order?->id)],
        'client' => ['required', 'string', new TenantExists(ProductionClient::class)],
        'ordered_on' => ['nullable', 'date'],
        'due_on' => ['nullable', 'date'],
        'status' => ['sometimes', Rule::in(['open', 'in_progress', 'completed', 'cancelled'])],
        'notes' => ['nullable', 'string', 'max:1000'],
        'lines' => ['sometimes', 'array', 'max:200'],
        'lines.*.article' => ['required', 'string', 'distinct', new TenantExists(Article::class)],
        'lines.*.quantity' => ['required', 'integer', 'between:1,100000000'],
    ];
    $saveOrder = function (CustomerOrder $order, array $input) use ($id) {
        return DB::transaction(function () use ($order, $input, $id) {
            $order->fill(['order_number' => trim($input['order_number']), 'production_client_id' => $id(ProductionClient::class, $input['client']),
                'ordered_on' => $input['ordered_on'] ?? null, 'due_on' => $input['due_on'] ?? null, 'status' => $input['status'] ?? ($order->status ?? 'open'), 'notes' => $input['notes'] ?? null])->save();
            if (array_key_exists('lines', $input)) {
                DB::table('customer_order_lines')->where('customer_order_id', $order->id)->delete();
                DB::table('customer_order_lines')->insert(array_map(fn (array $line) => ['company_id' => $order->company_id, 'customer_order_id' => $order->id,
                    'article_id' => $id(Article::class, $line['article']), 'quantity' => (int) $line['quantity'], 'created_at' => now(), 'updated_at' => now()], $input['lines']));
            }

            return $order;
        });
    };

    Route::get('orders', function (Request $request, ProductionData $data) use ($view, $like, $page, $paged) {
        $view($request);

        return $paged(CustomerOrder::query()
            ->when($request->string('status')->toString(), fn (Builder $query, string $value) => $query->where('status', $value))
            ->when($request->string('search')->toString(), fn (Builder $query, string $value) => $query->where('order_number', 'like', $like($value)))
            ->when($request->string('client')->toString(), fn (Builder $query, string $value) => $query->whereIn('production_client_id', ProductionClient::query()->select('id')->where('ulid', strtoupper($value))))
            ->orderByDesc('ordered_on')->orderByDesc('id')->paginate($page($request)), fn (CustomerOrder $order) => $data->order($order));
    })->name('production.orders.index');

    Route::post('orders', function (Request $request, ProductionData $data) use ($manage, $orderRules, $saveOrder) {
        $manage($request);

        return response()->json(['data' => $data->order($saveOrder(new CustomerOrder, $request->validate($orderRules())))], 201);
    })->name('production.orders.store');

    Route::put('orders/{order}', function (Request $request, CustomerOrder $order, ProductionData $data) use ($manage, $orderRules, $saveOrder) {
        $manage($request);

        return response()->json(['data' => $data->order($saveOrder($order, $request->validate($orderRules($order))))]);
    })->name('production.orders.update');

    /*
     * Manufacturing orders (OF): the stages they go through, each on its site, and what they consume.
     */
    $ofRules = fn (?ProductionOrder $of = null) => [
        'order_number' => ['required', 'string', 'max:60', Rule::unique('production_orders', 'order_number')->where('company_id', app(TenantContext::class)->companyId())->ignore($of?->id)],
        'article' => ['required', 'string', new TenantExists(Article::class)],
        'customer_order' => ['nullable', 'string', new TenantExists(CustomerOrder::class)],
        'quantity_planned' => ['required', 'integer', 'between:1,100000000'],
        'quantity_produced' => ['nullable', 'integer', 'between:0,100000000'],
        'started_on' => ['nullable', 'date'],
        'finished_on' => ['nullable', 'date', 'after_or_equal:started_on'],
        'status' => ['sometimes', Rule::in(['planned', 'in_progress', 'completed', 'cancelled'])],
        'notes' => ['nullable', 'string', 'max:1000'],
        'steps' => ['sometimes', 'array', 'max:30'],
        'steps.*.step' => ['required', 'string', 'distinct', Rule::exists('production_steps', 'code')->where(fn ($query) => $query->whereNull('company_id')->orWhere('company_id', app(TenantContext::class)->companyId()))],
        'steps.*.site' => ['required', 'string', new TenantExists(Site::class)],
        'steps.*.pieces_done' => ['nullable', 'integer', 'between:0,100000000'],
        'steps.*.started_on' => ['nullable', 'date'],
        'steps.*.finished_on' => ['nullable', 'date'],
        'consumptions' => ['sometimes', 'array', 'max:200'],
        'consumptions.*.material' => ['required', 'string', new TenantExists(RawMaterial::class)],
        'consumptions.*.batch' => ['nullable', 'string'],
        'consumptions.*.quantity' => ['required', 'decimal:0,3', 'gt:0'],
    ];
    $saveOf = function (ProductionOrder $of, array $input) use ($id) {
        return DB::transaction(function () use ($of, $input, $id) {
            $articleId = $id(Article::class, $input['article']);
            $orderId = $id(CustomerOrder::class, $input['customer_order'] ?? null);
            // An OF made for an order makes an article of that order.
            if ($orderId !== null && ! DB::table('customer_order_lines')->where('customer_order_id', $orderId)->where('article_id', $articleId)->exists()) {
                throw new BusinessRuleViolation('ARTICLE_NOT_IN_ORDER', 'This article is not on the chosen order.');
            }
            $of->fill(['order_number' => trim($input['order_number']), 'article_id' => $articleId, 'customer_order_id' => $orderId,
                'quantity_planned' => (int) $input['quantity_planned'], 'quantity_produced' => $input['quantity_produced'] ?? null,
                'started_on' => $input['started_on'] ?? null, 'finished_on' => $input['finished_on'] ?? null, 'status' => $input['status'] ?? ($of->status ?? 'planned'), 'notes' => $input['notes'] ?? null])->save();
            $base = ['company_id' => $of->company_id, 'production_order_id' => $of->id, 'created_at' => now(), 'updated_at' => now()];

            if (array_key_exists('steps', $input)) {
                DB::table('production_order_steps')->where('production_order_id', $of->id)->delete();
                DB::table('production_order_steps')->insert(array_map(fn (array $line, int $index) => $base + [
                    'production_step_id' => DB::table('production_steps')->where('code', $line['step'])->value('id'), 'site_id' => $id(Site::class, $line['site']),
                    'sequence' => $index + 1, 'pieces_done' => $line['pieces_done'] ?? null, 'started_on' => $line['started_on'] ?? null, 'finished_on' => $line['finished_on'] ?? null,
                ], $input['steps'], array_keys($input['steps'])));
            }
            if (array_key_exists('consumptions', $input)) {
                DB::table('production_order_consumptions')->where('production_order_id', $of->id)->delete();
                DB::table('production_order_consumptions')->insert(array_map(function (array $line) use ($base, $id) {
                    $materialId = $id(RawMaterial::class, $line['material']);
                    $batchId = isset($line['batch']) ? DB::table('raw_material_batches')->where('raw_material_id', $materialId)->where('ulid', strtoupper($line['batch']))->value('id') : null;
                    if (isset($line['batch']) && $batchId === null) {
                        throw ValidationException::withMessages(['consumptions' => [__('validation.exists', ['attribute' => 'batch'])]]);
                    }

                    return $base + ['raw_material_id' => $materialId, 'raw_material_batch_id' => $batchId, 'quantity' => $line['quantity']];
                }, $input['consumptions']));
            }

            return $of;
        });
    };

    Route::get('manufacturing-orders', function (Request $request, ProductionData $data) use ($view, $like, $page, $paged) {
        $view($request);

        return $paged(ProductionOrder::query()
            ->when($request->string('status')->toString(), fn (Builder $query, string $value) => $query->where('status', $value))
            ->when($request->string('search')->toString(), fn (Builder $query, string $value) => $query->where('order_number', 'like', $like($value)))
            ->when($request->string('customer_order')->toString(), fn (Builder $query, string $value) => $query->whereIn('customer_order_id', CustomerOrder::query()->select('id')->where('ulid', strtoupper($value))))
            ->when($request->string('site')->toString(), fn (Builder $query, string $value) => $query->whereIn('id', DB::table('production_order_steps as s')
                ->join('sites', 'sites.id', '=', 's.site_id')->where('sites.ulid', strtoupper($value))->select('s.production_order_id')))
            ->orderByDesc('started_on')->orderByDesc('id')->paginate($page($request)), fn (ProductionOrder $of) => $data->manufacturingOrder($of));
    })->name('production.manufacturing-orders.index');

    Route::get('manufacturing-orders/{of}', function (Request $request, ProductionOrder $of, ProductionData $data) use ($view) {
        $view($request);

        return response()->json(['data' => $data->manufacturingOrder($of, detailed: true)]);
    })->name('production.manufacturing-orders.show');

    Route::post('manufacturing-orders', function (Request $request, ProductionData $data) use ($manage, $ofRules, $saveOf) {
        $manage($request);

        return response()->json(['data' => $data->manufacturingOrder($saveOf(new ProductionOrder, $request->validate($ofRules())), detailed: true)], 201);
    })->name('production.manufacturing-orders.store');

    Route::put('manufacturing-orders/{of}', function (Request $request, ProductionOrder $of, ProductionData $data) use ($manage, $ofRules, $saveOf) {
        $manage($request);

        return response()->json(['data' => $data->manufacturingOrder($saveOf($of, $request->validate($ofRules($of))), detailed: true)]);
    })->name('production.manufacturing-orders.update');

    /*
     * Import from any spreadsheet: the user says in which column each piece of information is.
     */
    $targetRule = ['required', Rule::in(array_keys(ImportEngine::TARGETS))];
    $fileRule = ['required', 'file', 'mimes:xlsx,xls,csv,txt', 'max:10240'];
    $fields = fn (string $target) => collect(ImportEngine::TARGETS[$target])->map(fn (array $definition, string $field) => [
        'field' => $field, 'label' => __("imports.fields.{$target}.{$field}"), 'type' => $definition[0], 'required' => $definition[1],
    ])->values();
    $read = function (ImportEngine $engine, Request $request) {
        try {
            return $engine->read($request->file('file'));
        } catch (Throwable) {
            throw ValidationException::withMessages(['file' => [__('imports.errors.unreadable')]]);
        }
    };

    Route::get('imports/targets', function (Request $request) use ($manage, $fields) {
        $manage($request);

        return ['data' => collect(array_keys(ImportEngine::TARGETS))->map(fn (string $target) => ['target' => $target, 'fields' => $fields($target)])];
    })->name('production.imports.targets');

    // An empty file with the expected headings, for who has no export to start from.
    Route::get('imports/targets/{target}/template', function (Request $request, string $target) use ($manage, $fields) {
        $manage($request);
        abort_unless(isset(ImportEngine::TARGETS[$target]), 404);

        return response(Excel::raw(new ArrayExport(__("imports.targets.{$target}"), $fields($target)->pluck('label')->all(), []), ExcelFormat::XLSX), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.Str::slug(__("imports.targets.{$target}")).'.xlsx"', 'Cache-Control' => 'private, no-store',
        ]);
    })->name('production.imports.template');

    // Step 1: the headings of the file, its first lines, and where each piece of information seems to be.
    Route::post('imports/preview', function (Request $request, ImportEngine $engine) use ($manage, $targetRule, $fileRule, $fields, $read) {
        $manage($request);
        $input = $request->validate(['target' => $targetRule, 'file' => $fileRule]);
        $sheet = $read($engine, $request);

        return ['data' => ['headers' => $sheet['headers'], 'sample' => array_slice($sheet['rows'], 0, 5), 'lines' => count($sheet['rows']),
            'fields' => $fields($input['target']), 'mapping' => $engine->suggest($input['target'], $sheet['headers'])]];
    })->name('production.imports.preview');

    // Step 2: check (`dry_run`) or import. A line in error is skipped and reported; the others are written.
    Route::post('imports/run', function (Request $request, ImportEngine $engine) use ($manage, $targetRule, $fileRule, $read) {
        $manage($request);
        $input = $request->validate(['target' => $targetRule, 'file' => $fileRule, 'dry_run' => ['sometimes', 'boolean'], 'mapping' => ['required', 'array'], 'mapping.*' => ['nullable', 'integer', 'min:0']]);
        $sheet = $read($engine, $request);
        $mapping = [];
        foreach (ImportEngine::TARGETS[$input['target']] as $field => [, $required]) {
            $column = $input['mapping'][$field] ?? null;
            $mapping[$field] = $column === null || $column === '' ? null : (int) $column;
            if ($mapping[$field] !== null && ! array_key_exists($mapping[$field], $sheet['headers'])) {
                throw ValidationException::withMessages(["mapping.{$field}" => [__('validation.exists', ['attribute' => $field])]]);
            }
            if ($required && $mapping[$field] === null) {
                throw ValidationException::withMessages(["mapping.{$field}" => [__('imports.errors.column_required', ['field' => __("imports.fields.{$input['target']}.{$field}")])]]);
            }
        }

        return ['data' => $engine->run($input['target'], $sheet['rows'], $mapping, $request->boolean('dry_run')) + ['dry_run' => $request->boolean('dry_run')]];
    })->name('production.imports.run');
});
