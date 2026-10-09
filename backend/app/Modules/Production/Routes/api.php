<?php

use App\Modules\Catalog\Support\Colors;
use App\Modules\Common\Exceptions\BusinessRuleViolation;
use App\Modules\Common\Support\DocumentLanguage;
use App\Modules\Common\Support\ReferenceCodes;
use App\Modules\Identity\Authorization\AccessResolver;
use App\Modules\Production\Models\Article;
use App\Modules\Production\Models\CustomerOrder;
use App\Modules\Production\Models\CustomerOrderInvoice;
use App\Modules\Production\Models\ProducedLot;
use App\Modules\Production\Models\ProductionClient;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Production\Models\ProductionOrderExpense;
use App\Modules\Production\Models\ProductionSupplier;
use App\Modules\Production\Models\RawMaterial;
use App\Modules\Production\Models\RawMaterialBatch;
use App\Modules\Production\Support\FinanceReport;
use App\Modules\Production\Support\ImportEngine;
use App\Modules\Production\Support\LotOrigin;
use App\Modules\Production\Support\ProductionData;
use App\Modules\Production\Support\ProductionFinance;
use App\Modules\Production\Support\ProductionStats;
use App\Modules\Production\Support\ProductionVolumes;
use App\Modules\Reporting\Support\ArrayExport;
use App\Modules\Sites\Models\Site;
use App\Modules\Tenancy\Rules\TenantExists;
use App\Modules\Tenancy\TenantContext;
use Barryvdh\DomPDF\Facade\Pdf;
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
    // Prices, expenses, invoices and gains: read by who may see money, written by who may also manage production.
    $seeMoney = fn (Request $request) => abort_unless($request->user()->can('production.finance.view'), 403);
    $manageMoney = fn (Request $request) => abort_unless($request->user()->can('production.manage') && $request->user()->can('production.finance.view'), 403);
    $currencyRule = ['nullable', 'string', 'size:3', Rule::exists('currencies', 'code')];
    $stepRule = fn () => Rule::exists('production_steps', 'code')->where(fn ($query) => $query->whereNull('company_id')->orWhere('company_id', app(TenantContext::class)->companyId()));
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
        'category' => ['nullable', 'string', 'max:80'],
        'supplier' => ['nullable', 'string', new TenantExists(ProductionSupplier::class)],
        'unit' => ['required', 'string', Rule::in(ProductionData::MATERIAL_UNITS)],
        'color_family' => ['nullable', 'string', Colors::rule(app(TenantContext::class)->companyId())],
        'color_label' => ['nullable', 'string', 'max:100'],
        'grammage_gsm' => ['nullable', 'decimal:0,2', 'gt:0'],
        'width_cm' => ['nullable', 'decimal:0,2', 'gt:0', 'lte:1000'],
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
            'reference' => trim($input['reference']), 'name' => trim($input['name']), 'category' => $input['category'] ?? null, 'production_supplier_id' => $id(ProductionSupplier::class, $input['supplier'] ?? null),
            'unit_id' => $codes->id('units', $input['unit']), 'color_family_id' => $codes->id('color_families', $input['color_family'] ?? null),
            'color_label' => $input['color_label'] ?? null, 'grammage_gsm' => $input['grammage_gsm'] ?? null, 'width_cm' => $input['width_cm'] ?? null, 'composition' => $composition === [] ? null : $composition,
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

    Route::get('materials/{material}', function (Request $request, RawMaterial $material, ProductionData $data) use ($view) {
        $view($request);

        return response()->json(['data' => $data->material($material, detailed: true)]);
    })->name('production.materials.show');

    /*
     * Received lots: each delivery of a material, with the supplier who made it and what it cost. They are kept for
     * traceability: there is no stock here, no balance and no movement.
     */
    $batchValues = function (Request $request, RawMaterial $material, ?RawMaterialBatch $batch = null) use ($id, $currencyRule) {
        $input = $request->validate([
            'batch_number' => ['required', 'string', 'max:60', Rule::unique('raw_material_batches', 'batch_number')->where('raw_material_id', $material->id)->ignore($batch?->id)],
            'supplier' => ['nullable', 'string', new TenantExists(ProductionSupplier::class)],
            'supplier_batch_reference' => ['nullable', 'string', 'max:80'],
            'received_on' => ['nullable', 'date'],
            'quantity' => ['nullable', 'decimal:0,3', 'gt:0'],
            'unit_price' => ['nullable', 'decimal:0,4', 'gte:0'],
            'total_amount' => ['nullable', 'decimal:0,3', 'gte:0'],
            'delivery_cost' => ['nullable', 'decimal:0,3', 'gte:0'],
            'currency_code' => $currencyRule,
            'tax_rate_pct' => ['nullable', 'decimal:0,2', 'between:0,100'],
            'delivery_note' => ['nullable', 'string', 'max:80'],
            'invoice_reference' => ['nullable', 'string', 'max:80'],
            'external_reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $number = trim($input['batch_number']);
        // Orders and waste lots name the lot by its number on their documents: it stays once something points to it.
        if ($batch !== null && $number !== $batch->getAttribute('batch_number') && (DB::table('production_order_consumptions')->where('raw_material_batch_id', $batch->id)->exists()
            || DB::table('waste_lots')->where('raw_material_batch_id', $batch->id)->exists())) {
            throw new BusinessRuleViolation('RECEIVED_LOT_IN_USE', 'The number of a received lot cannot change once an order or a waste lot names it.');
        }
        $values = [
            'batch_number' => $number,
            // Not said: the supplier the material proposes when the lot is created, the one the lot already has afterwards.
            'production_supplier_id' => array_key_exists('supplier', $input) ? $id(ProductionSupplier::class, $input['supplier'])
                : ($batch === null ? $material->production_supplier_id : $batch->getAttribute('production_supplier_id')),
            'supplier_batch_reference' => $input['supplier_batch_reference'] ?? null, 'received_on' => $input['received_on'] ?? null, 'quantity' => $input['quantity'] ?? null,
            'delivery_note' => $input['delivery_note'] ?? null, 'invoice_reference' => $input['invoice_reference'] ?? null, 'external_reference' => $input['external_reference'] ?? null,
            'notes' => $input['notes'] ?? null,
        ];
        // Amounts are written only by who may see them; for anyone else the lot keeps the ones it has.
        if ($request->user()->can('production.finance.view')) {
            $amounts = ProductionFinance::reconcile($values['quantity'], $input['unit_price'] ?? null, $input['total_amount'] ?? null)
                ?? throw ValidationException::withMessages(['total_amount' => [__('validation.custom.production.amount_mismatch')]]);
            $priced = $amounts['unit_price'] !== null || $amounts['total_amount'] !== null || isset($input['delivery_cost']);
            $values += $amounts + ['delivery_cost' => $input['delivery_cost'] ?? null, 'tax_rate_pct' => $input['tax_rate_pct'] ?? null,
                'currency_code' => $priced ? ($input['currency_code'] ?? DB::table('companies')->where('id', $material->company_id)->value('currency_code')) : null];
        }

        return $values;
    };

    Route::get('received-lots', function (Request $request, ProductionData $data, ProductionFinance $finance, TenantContext $context) use ($view, $like, $page, $paged) {
        $view($request);
        $input = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'priced' => ['nullable', Rule::in(['yes', 'no'])]]);
        $paginator = $data->batches()->join('raw_materials as m', 'm.id', '=', 'b.raw_material_id')->join('units as u', 'u.id', '=', 'm.unit_id')
            ->addSelect(['m.ulid as material', 'm.reference as material_reference', 'm.name as material_name', 'u.code as unit'])
            ->where('b.company_id', $context->companyId())
            ->when($request->string('search')->toString(), fn ($query, string $value) => $query->where(fn ($q) => $q->where('b.batch_number', 'like', $like($value))
                ->orWhere('b.supplier_batch_reference', 'like', $like($value))->orWhere('m.reference', 'like', $like($value))->orWhere('m.name', 'like', $like($value))))
            ->when($request->string('supplier')->toString(), fn ($query, string $value) => $query->where('s.ulid', strtoupper($value)))
            ->when($request->string('material')->toString(), fn ($query, string $value) => $query->where('m.ulid', strtoupper($value)))
            ->when($input['from'] ?? null, fn ($query, string $value) => $query->where('b.received_on', '>=', $value))
            ->when($input['to'] ?? null, fn ($query, string $value) => $query->where('b.received_on', '<=', $value))
            ->when(($input['priced'] ?? null) === 'no', fn ($query) => $query->whereNull('b.unit_price')->whereNull('b.total_amount'))
            ->when(($input['priced'] ?? null) === 'yes', fn ($query) => $query->where(fn ($q) => $q->whereNotNull('b.unit_price')->orWhereNotNull('b.total_amount')))
            ->orderByDesc('b.received_on')->orderByDesc('b.id')->paginate($page($request));
        $consumed = $finance->consumedPerLot(collect($paginator->items())->pluck('id')->all());

        return $paged($paginator, fn (stdClass $row) => $data->batch($row, $consumed)
            + ['material' => ['ulid' => $row->material, 'reference' => $row->material_reference, 'name' => $row->material_name, 'unit' => $row->unit]]);
    })->name('production.received-lots.index');

    Route::post('materials/{material}/batches', function (Request $request, RawMaterial $material, ProductionData $data) use ($manage, $batchValues) {
        $manage($request);
        RawMaterialBatch::query()->create($batchValues($request, $material) + ['raw_material_id' => $material->id]);

        return response()->json(['data' => $data->material($material, detailed: true)], 201);
    })->name('production.materials.batches.store');

    Route::put('batches/{batch}', function (Request $request, RawMaterialBatch $batch, ProductionData $data) use ($manage, $batchValues) {
        $manage($request);
        $material = RawMaterial::query()->findOrFail($batch->getAttribute('raw_material_id'));
        $batch->update($batchValues($request, $material, $batch));

        return response()->json(['data' => $data->material($material, detailed: true)]);
    })->name('production.batches.update');

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
        // What the client agreed to pay for one piece, excluding tax.
        'lines.*.unit_price' => ['nullable', 'decimal:0,4', 'gte:0'],
        'currency_code' => $currencyRule,
        'discount_amount' => ['nullable', 'decimal:0,3', 'gte:0'],
    ];
    $saveOrder = function (CustomerOrder $order, array $input) use ($id) {
        return DB::transaction(function () use ($order, $input, $id) {
            // Prices are written only by who may see them; for anyone else each article keeps the price it has.
            $money = (bool) auth()->user()?->can('production.finance.view');
            $prices = $order->exists ? DB::table('customer_order_lines')->where('customer_order_id', $order->id)->pluck('unit_price', 'article_id') : collect();
            if ($money) {
                $order->fill(['currency_code' => $input['currency_code'] ?? null, 'discount_amount' => $input['discount_amount'] ?? null]);
            }
            $order->fill(['order_number' => trim($input['order_number']), 'production_client_id' => $id(ProductionClient::class, $input['client']),
                'ordered_on' => $input['ordered_on'] ?? null, 'due_on' => $input['due_on'] ?? null, 'status' => $input['status'] ?? ($order->status ?? 'open'), 'notes' => $input['notes'] ?? null])->save();
            if (array_key_exists('lines', $input)) {
                DB::table('customer_order_lines')->where('customer_order_id', $order->id)->delete();
                DB::table('customer_order_lines')->insert(array_map(fn (array $line) => ['company_id' => $order->company_id, 'customer_order_id' => $order->id,
                    'article_id' => $articleId = $id(Article::class, $line['article']), 'quantity' => (int) $line['quantity'],
                    'unit_price' => $money ? ($line['unit_price'] ?? null) : ($prices[$articleId] ?? null), 'created_at' => now(), 'updated_at' => now()], $input['lines']));
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

    Route::get('orders/{order}', function (Request $request, CustomerOrder $order, ProductionData $data) use ($view) {
        $view($request);

        return response()->json(['data' => $data->order($order, detailed: true)]);
    })->name('production.orders.show');

    /*
     * Invoices and credit notes of an order. They are issued elsewhere; recording them here is what lets the screen say
     * "ordered", "produced" and "really invoiced" as three different amounts.
     */
    $invoiceValues = function (Request $request, CustomerOrder $order, ?CustomerOrderInvoice $invoice = null) use ($id, $currencyRule) {
        $input = $request->validate([
            'kind' => ['required', Rule::in(['invoice', 'credit_note'])],
            'number' => ['required', 'string', 'max:60', Rule::unique('customer_order_invoices', 'number')->where('company_id', $order->getAttribute('company_id'))->ignore($invoice?->id)],
            'issued_on' => ['nullable', 'date'],
            'amount' => ['required', 'decimal:0,3', 'gt:0'],
            'currency_code' => $currencyRule,
            // When the invoice is for one OF of the order.
            'manufacturing_order' => ['nullable', 'string', new TenantExists(ProductionOrder::class, fn ($query) => $query->where('customer_order_id', $order->id))],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        return ['kind' => $input['kind'], 'number' => trim($input['number']), 'issued_on' => $input['issued_on'] ?? null, 'amount' => $input['amount'],
            'currency_code' => $input['currency_code'] ?? $order->getAttribute('currency_code') ?? DB::table('companies')->where('id', $order->getAttribute('company_id'))->value('currency_code'),
            'production_order_id' => $id(ProductionOrder::class, $input['manufacturing_order'] ?? null), 'notes' => $input['notes'] ?? null];
    };

    Route::post('orders/{order}/invoices', function (Request $request, CustomerOrder $order, ProductionData $data) use ($manageMoney, $invoiceValues) {
        $manageMoney($request);
        CustomerOrderInvoice::query()->create($invoiceValues($request, $order) + ['customer_order_id' => $order->id]);

        return response()->json(['data' => $data->order($order, detailed: true)], 201);
    })->name('production.orders.invoices.store');

    Route::put('invoices/{invoice}', function (Request $request, CustomerOrderInvoice $invoice, ProductionData $data) use ($manageMoney, $invoiceValues) {
        $manageMoney($request);
        $order = CustomerOrder::query()->findOrFail($invoice->getAttribute('customer_order_id'));
        $invoice->update($invoiceValues($request, $order, $invoice));

        return response()->json(['data' => $data->order($order, detailed: true)]);
    })->name('production.invoices.update');

    // Entered by mistake: removed, and the removal stays in the history.
    Route::delete('invoices/{invoice}', function (Request $request, CustomerOrderInvoice $invoice, ProductionData $data) use ($manageMoney) {
        $manageMoney($request);
        $order = CustomerOrder::query()->findOrFail($invoice->getAttribute('customer_order_id'));
        $invoice->delete();

        return response()->json(['data' => $data->order($order, detailed: true)]);
    })->name('production.invoices.destroy');

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
        'steps.*.step' => ['required', 'string', 'distinct', $stepRule()],
        'steps.*.site' => ['required', 'string', new TenantExists(Site::class)],
        'steps.*.pieces_done' => ['nullable', 'integer', 'between:0,100000000'],
        'steps.*.started_on' => ['nullable', 'date'],
        'steps.*.finished_on' => ['nullable', 'date'],
        'consumptions' => ['sometimes', 'array', 'max:200'],
        'consumptions.*.material' => ['required', 'string', new TenantExists(RawMaterial::class)],
        'consumptions.*.batch' => ['nullable', 'string'],
        'consumptions.*.quantity' => ['required', 'decimal:0,3', 'gt:0'],
        'consumptions.*.step' => ['nullable', 'string', $stepRule()],
        'consumptions.*.site' => ['nullable', 'string', new TenantExists(Site::class)],
        'consumptions.*.consumed_on' => ['nullable', 'date'],
        'consumptions.*.notes' => ['nullable', 'string', 'max:500'],
    ];
    // What the OF consumed. One line per material and received lot: 12 kg of lot A and 8 kg of lot B are two lines. A
    // line without a lot is "lot non précisé": it is kept as such, no lot is ever chosen for it.
    $saveConsumptions = function (ProductionOrder $of, array $lines) use ($id) {
        $base = ['company_id' => $of->getAttribute('company_id'), 'production_order_id' => $of->id, 'created_at' => now(), 'updated_at' => now()];
        DB::table('production_order_consumptions')->where('production_order_id', $of->id)->delete();
        DB::table('production_order_consumptions')->insert(array_map(function (array $line) use ($base, $id) {
            $materialId = $id(RawMaterial::class, $line['material']);
            $batchId = isset($line['batch']) ? DB::table('raw_material_batches')->where('raw_material_id', $materialId)->where('ulid', strtoupper($line['batch']))->value('id') : null;
            if (isset($line['batch']) && $batchId === null) {
                throw ValidationException::withMessages(['consumptions' => [__('validation.custom.production.batch_of_material')]]);
            }

            return $base + ['raw_material_id' => $materialId, 'raw_material_batch_id' => $batchId, 'quantity' => $line['quantity'],
                'production_step_id' => isset($line['step']) ? DB::table('production_steps')->where('code', $line['step'])->value('id') : null,
                'site_id' => $id(Site::class, $line['site'] ?? null), 'consumed_on' => $line['consumed_on'] ?? null, 'notes' => $line['notes'] ?? null];
        }, $lines));
    };
    // The pieces made by an OF are the sum of its produced lots as soon as it has some.
    $syncProduced = function (ProductionOrder $of) {
        if (DB::table('produced_lots')->where('production_order_id', $of->id)->exists()) {
            $of->forceFill(['quantity_produced' => (int) DB::table('produced_lots')->where('production_order_id', $of->id)->where('status', '!=', 'cancelled')->sum('quantity')])->save();
        }
        ProductionVolumes::sync((int) $of->getAttribute('company_id'));
    };
    $saveOf = function (ProductionOrder $of, array $input) use ($id, $saveConsumptions) {
        return DB::transaction(function () use ($of, $input, $id, $saveConsumptions) {
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
                $saveConsumptions($of, $input['consumptions']);
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

    Route::post('manufacturing-orders', function (Request $request, ProductionData $data) use ($manage, $ofRules, $saveOf, $syncProduced) {
        $manage($request);

        $of = $saveOf(new ProductionOrder, $request->validate($ofRules()));
        $syncProduced($of);

        return response()->json(['data' => $data->manufacturingOrder($of, detailed: true)], 201);
    })->name('production.manufacturing-orders.store');

    Route::put('manufacturing-orders/{of}', function (Request $request, ProductionOrder $of, ProductionData $data) use ($manage, $ofRules, $saveOf, $syncProduced) {
        $manage($request);

        $of = $saveOf($of, $request->validate($ofRules($of)));
        $syncProduced($of);

        return response()->json(['data' => $data->manufacturingOrder($of, detailed: true)]);
    })->name('production.manufacturing-orders.update');

    // Only what the OF consumed, without sending the whole order again.
    Route::put('manufacturing-orders/{of}/consumptions', function (Request $request, ProductionOrder $of, ProductionData $data) use ($manage, $ofRules, $saveConsumptions) {
        $manage($request);
        $rules = array_filter($ofRules($of), fn (string $key) => str_starts_with($key, 'consumptions'), ARRAY_FILTER_USE_KEY);
        $input = $request->validate(['consumptions' => ['present', 'array', 'max:200']] + $rules);
        DB::transaction(fn () => $saveConsumptions($of, $input['consumptions']));

        return response()->json(['data' => $data->manufacturingOrder($of, detailed: true)]);
    })->name('production.manufacturing-orders.consumptions');

    /*
     * Produced lots (lots fabriqués): the finished goods an OF made, in one or several batches. The client and the order
     * are those of the OF. There is no supplier here — suppliers belong to the received lots the OF consumed — and no
     * stock: a produced lot is a record of what was made and, when it left, of when.
     */
    $producedValues = function (Request $request, ProductionOrder $of, ?ProducedLot $lot = null) use ($id, $stepRule) {
        $input = $request->validate([
            'lot_number' => ['required', 'string', 'max:60', Rule::unique('produced_lots', 'lot_number')->where('company_id', $of->getAttribute('company_id'))->ignore($lot?->id)],
            'quantity' => ['required', 'integer', 'between:1,100000000'],
            'produced_on' => ['nullable', 'date'],
            'produced_until' => ['nullable', 'date', 'after_or_equal:produced_on'],
            'step' => ['nullable', 'string', $stepRule()],
            'site' => ['nullable', 'string', new TenantExists(Site::class)],
            'status' => ['sometimes', Rule::in(ProductionData::PRODUCED_LOT_STATUSES)],
            'delivered_on' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'external_reference' => ['nullable', 'string', 'max:100'],
        ]);
        $status = $input['status'] ?? ($lot?->getAttribute('status') ?? 'produced');

        return ['lot_number' => trim($input['lot_number']), 'quantity' => (int) $input['quantity'], 'produced_on' => $input['produced_on'] ?? null, 'produced_until' => $input['produced_until'] ?? null,
            'production_step_id' => isset($input['step']) ? DB::table('production_steps')->where('code', $input['step'])->value('id') : null, 'site_id' => $id(Site::class, $input['site'] ?? null),
            'status' => $status, 'delivered_on' => $status === 'delivered' ? ($input['delivered_on'] ?? now()->toDateString()) : null,
            'notes' => $input['notes'] ?? null, 'external_reference' => $input['external_reference'] ?? null];
    };

    Route::get('produced-lots', function (Request $request, ProductionData $data, TenantContext $context) use ($view, $like, $page, $paged) {
        $view($request);
        $input = $request->validate(['status' => ['nullable', Rule::in(ProductionData::PRODUCED_LOT_STATUSES)], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);

        return $paged($data->producedLots()->where('p.company_id', $context->companyId())
            ->when($input['status'] ?? null, fn ($query, string $value) => $query->where('p.status', $value))
            ->when($request->string('search')->toString(), fn ($query, string $value) => $query->where(fn ($q) => $q->where('p.lot_number', 'like', $like($value))
                ->orWhere('o.order_number', 'like', $like($value))->orWhere('a.reference', 'like', $like($value))->orWhere('c.order_number', 'like', $like($value))))
            ->when($request->string('client')->toString(), fn ($query, string $value) => $query->where('cl.ulid', strtoupper($value)))
            ->when($request->string('manufacturing_order')->toString(), fn ($query, string $value) => $query->where('o.ulid', strtoupper($value)))
            ->when($input['from'] ?? null, fn ($query, string $value) => $query->where('p.produced_on', '>=', $value))
            ->when($input['to'] ?? null, fn ($query, string $value) => $query->where('p.produced_on', '<=', $value))
            ->orderByDesc('p.produced_on')->orderByDesc('p.id')->paginate($page($request)), fn (stdClass $lot) => $data->producedLot($lot));
    })->name('production.produced-lots.index');

    Route::post('manufacturing-orders/{of}/produced-lots', function (Request $request, ProductionOrder $of, ProductionData $data, ReferenceCodes $codes) use ($manage, $producedValues, $syncProduced) {
        $manage($request);
        ProducedLot::query()->create($producedValues($request, $of) + ['production_order_id' => $of->id, 'unit_id' => $codes->id('units', 'piece')]);
        $syncProduced($of);

        return response()->json(['data' => $data->manufacturingOrder($of->refresh(), detailed: true)], 201);
    })->name('production.manufacturing-orders.produced-lots.store');

    // A produced lot is corrected or cancelled, never deleted: delivery notes outside the application name it.
    Route::put('produced-lots/{producedLot}', function (Request $request, ProducedLot $producedLot, ProductionData $data) use ($manage, $producedValues, $syncProduced) {
        $manage($request);
        $of = ProductionOrder::query()->findOrFail($producedLot->getAttribute('production_order_id'));
        $producedLot->update($producedValues($request, $of, $producedLot));
        $syncProduced($of);

        return response()->json(['data' => $data->manufacturingOrder($of->refresh(), detailed: true)]);
    })->name('production.produced-lots.update');

    /*
     * Other expenses of an OF: what it cost beside its materials. Nothing is assumed: labour or machine time counts
     * only if someone enters it.
     */
    $expenseValues = function (Request $request, ProductionOrder $of) use ($id, $currencyRule) {
        $input = $request->validate([
            'category' => ['required', Rule::in(ProductionData::EXPENSE_CATEGORIES)],
            'description' => ['required', 'string', 'max:191'],
            'amount' => ['required', 'decimal:0,3', 'gt:0'],
            'currency_code' => $currencyRule,
            'incurred_on' => ['nullable', 'date'],
            'supplier' => ['nullable', 'string', new TenantExists(ProductionSupplier::class)],
            'reference' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        return ['category' => $input['category'], 'description' => trim($input['description']), 'amount' => $input['amount'],
            'currency_code' => $input['currency_code'] ?? DB::table('companies')->where('id', $of->getAttribute('company_id'))->value('currency_code'),
            'incurred_on' => $input['incurred_on'] ?? null, 'production_supplier_id' => $id(ProductionSupplier::class, $input['supplier'] ?? null),
            'reference' => $input['reference'] ?? null, 'notes' => $input['notes'] ?? null];
    };

    Route::post('manufacturing-orders/{of}/expenses', function (Request $request, ProductionOrder $of, ProductionData $data) use ($manageMoney, $expenseValues) {
        $manageMoney($request);
        ProductionOrderExpense::query()->create($expenseValues($request, $of) + ['production_order_id' => $of->id]);

        return response()->json(['data' => $data->manufacturingOrder($of, detailed: true)], 201);
    })->name('production.manufacturing-orders.expenses.store');

    Route::put('expenses/{expense}', function (Request $request, ProductionOrderExpense $expense, ProductionData $data) use ($manageMoney, $expenseValues) {
        $manageMoney($request);
        $of = ProductionOrder::query()->findOrFail($expense->getAttribute('production_order_id'));
        $expense->update($expenseValues($request, $of));

        return response()->json(['data' => $data->manufacturingOrder($of, detailed: true)]);
    })->name('production.expenses.update');

    // Entered by mistake: removed, and the removal stays in the history.
    Route::delete('expenses/{expense}', function (Request $request, ProductionOrderExpense $expense, ProductionData $data) use ($manageMoney) {
        $manageMoney($request);
        $of = ProductionOrder::query()->findOrFail($expense->getAttribute('production_order_id'));
        $expense->delete();

        return response()->json(['data' => $data->manufacturingOrder($of, detailed: true)]);
    })->name('production.expenses.destroy');

    /*
     * Costs and gains of the orders of a period, with what is missing to trust them.
     */
    $financeInput = function (Request $request) use ($seeMoney) {
        $seeMoney($request);
        $input = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from']]);

        return [$input['from'], $input['to']];
    };

    Route::get('reports/finance', fn (Request $request, FinanceReport $report) => ['data' => $report->compute(...$financeInput($request))])->name('production.reports.finance');

    Route::get('reports/finance/export', function (Request $request, FinanceReport $report) use ($financeInput) {
        $data = $report->compute(...$financeInput($request));
        $number = fn (?string $value) => $value === null ? null : (float) $value;
        $columns = ['order_number', 'article', 'client', 'customer_order', 'status', 'quantity_planned', 'quantity_produced', 'planned_material_cost', 'material_cost_known',
            'material_cost_estimated', 'expenses', 'total_cost', 'unit_cost', 'unit_price', 'produced_value', 'invoiced', 'gain', 'margin_pct', 'missing'];
        $rows = array_map(fn (array $row) => [$row['order_number'], $row['article'], $row['client'], $row['customer_order'], __('exports.production.status.'.$row['status']), $row['quantity_planned'],
            $row['quantity_produced'], $number($row['planned_material_cost']), $number($row['material_cost_known']), $number($row['material_cost_estimated']), $number($row['expenses']),
            $number($row['total_cost']), $number($row['unit_cost']), $number($row['unit_price']), $number($row['produced_value']), $number($row['invoiced']), $number($row['gain']), $row['margin_pct'],
            implode(', ', array_map(fn (string $reason) => __('exports.production.missing.'.$reason), $row['missing']))], $data['orders']);

        return response(Excel::raw(new ArrayExport(__('exports.production.finance_sheet'), array_map(fn (string $key) => __("exports.production.finance_columns.{$key}", ['currency' => $data['currency']]), $columns), $rows), ExcelFormat::XLSX), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="couts-gains-'.$data['period']['from'].'-'.$data['period']['to'].'.xlsx"', 'Cache-Control' => 'private, no-store',
        ]);
    })->name('production.reports.finance.export');

    // The manufacturing orders a lot can be attached to: for whoever creates lots, on the sites where he may. Only
    // what is needed to choose one — an operator does not see clients or quantities.
    Route::get('open-orders', function (Request $request, AccessResolver $access, LotOrigin $origin) {
        $siteIds = $access->siteIdsFor($request->user(), 'lots.create');
        abort_if($siteIds === [], 403);

        return ['data' => $origin->openOrders($siteIds)];
    })->name('production.open-orders');

    /*
     * Statistics: where the waste of a period comes from, measured (the lot names its order) and estimated (the lot
     * only says its stage) kept apart.
     */
    $statsInput = function (Request $request) use ($id) {
        abort_unless($request->user()->can('production.stats.view'), 403);
        $input = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from'], 'site' => ['nullable', 'string', new TenantExists(Site::class)]]);

        return [$input['from'], $input['to'], $id(Site::class, $input['site'] ?? null)];
    };

    Route::get('stats', fn (Request $request, ProductionStats $stats) => ['data' => $stats->compute(...$statsInput($request))])->name('production.stats');

    // The same figures as a document, in the language of the user or the one asked for.
    Route::get('stats/pdf', function (Request $request, ProductionStats $stats, TenantContext $context) use ($statsInput) {
        [$from, $to, $siteId] = $statsInput($request);
        $company = DB::table('companies')->where('id', $context->companyId())->first(['legal_name', 'timezone']);
        $content = DocumentLanguage::during(DocumentLanguage::of($request), fn () => Pdf::loadView('pdf.production-stats', [
            'stats' => $stats->compute($from, $to, $siteId), 'company' => $company->legal_name, 'timezone' => (string) $company->timezone,
            'site' => $siteId === null ? null : DB::table('sites')->where('id', $siteId)->value('name'),
        ])->setPaper('a4', 'landscape')->output());

        return response($content, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="production-'.$from.'-'.$to.'.pdf"', 'Cache-Control' => 'private, no-store']);
    })->name('production.stats.pdf');

    Route::get('stats/export', function (Request $request, ProductionStats $stats) use ($statsInput) {
        $data = $stats->compute(...$statsInput($request));
        $rows = [];
        foreach (['by_order', 'by_client', 'by_article', 'by_step', 'by_material', 'by_supplier'] as $section) {
            foreach ($data[$section] as $row) {
                $label = is_array($row['label']) ? ($row['label'][app()->getLocale()] ?? reset($row['label'])) : $row['label'];
                $rows[] = [__("exports.production.{$section}"), $label, $row['sublabel'], $row['lots'], (float) $row['measured_kg'], (float) $row['estimated_kg'], (float) $row['total_kg'],
                    $row['pieces'], $row['g_per_piece'], $row['consumed_kg'] === null ? null : (float) $row['consumed_kg'], $row['waste_rate_pct'], (float) $row['net_amount']];
            }
        }
        $rows[] = [__('exports.production.unattributed'), null, null, $data['totals']['unattributed_lots'], null, null, (float) $data['totals']['unattributed_kg'], null, null, null, null, null];

        return response(Excel::raw(new ArrayExport(__('exports.production.sheet'), array_map(fn (string $key) => __("exports.production.columns.{$key}"),
            ['section', 'label', 'detail', 'lots', 'measured_kg', 'estimated_kg', 'total_kg', 'pieces', 'g_per_piece', 'consumed_kg', 'waste_rate_pct', 'net_amount']), $rows), ExcelFormat::XLSX), 200, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="production-'.$data['period']['from'].'-'.$data['period']['to'].'.xlsx"', 'Cache-Control' => 'private, no-store',
            ]);
    })->name('production.stats.export');

    /*
     * Import from any spreadsheet: the user says in which column each piece of information is.
     */
    $targetRule = ['required', Rule::in(array_keys(ImportEngine::TARGETS))];
    $fileRule = ['required', 'file', 'mimes:xlsx,xls,csv,txt', 'max:10240'];
    $fields = fn (string $target) => collect(ImportEngine::TARGETS[$target])->map(fn (array $definition, string $field) => [
        'field' => $field, 'label' => __("imports.fields.{$target}.{$field}"), 'type' => $definition[0], 'required' => $definition[1],
        'example' => ImportEngine::EXAMPLES[$field] ?? null, 'money' => in_array($field, ImportEngine::MONEY_FIELDS, true),
    ])->values();
    // Amounts come in only through who may see them: a file cannot be a way around that right.
    $moneyGuard = function (Request $request, string $target, array $mapping = []) {
        $touches = in_array($target, ImportEngine::MONEY_TARGETS, true)
            || array_filter(array_intersect_key($mapping, array_flip(ImportEngine::MONEY_FIELDS)), fn ($column) => $column !== null && $column !== '') !== [];
        abort_if($touches && ! $request->user()->can('production.finance.view'), 403);
    };
    $read = function (ImportEngine $engine, Request $request) {
        try {
            return $engine->read($request->file('file'));
        } catch (Throwable) {
            throw ValidationException::withMessages(['file' => [__('imports.errors.unreadable')]]);
        }
    };

    Route::get('imports/targets', function (Request $request) use ($manage, $fields) {
        $manage($request);

        return ['data' => collect(array_keys(ImportEngine::TARGETS))
            ->reject(fn (string $target) => in_array($target, ImportEngine::MONEY_TARGETS, true) && ! $request->user()->can('production.finance.view'))
            ->map(fn (string $target) => ['target' => $target, 'fields' => $fields($target)])->values()];
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
    Route::post('imports/preview', function (Request $request, ImportEngine $engine) use ($manage, $targetRule, $fileRule, $fields, $read, $moneyGuard) {
        $manage($request);
        $input = $request->validate(['target' => $targetRule, 'file' => $fileRule]);
        $moneyGuard($request, $input['target']);
        $sheet = $read($engine, $request);

        return ['data' => ['headers' => $sheet['headers'], 'sample' => array_slice($sheet['rows'], 0, 5), 'lines' => count($sheet['rows']),
            'fields' => $fields($input['target']), 'mapping' => $engine->suggest($input['target'], $sheet['headers'])]];
    })->name('production.imports.preview');

    // Step 2: check (`dry_run`) or import. A line in error is skipped and reported; the others are written.
    Route::post('imports/run', function (Request $request, ImportEngine $engine) use ($manage, $targetRule, $fileRule, $read, $moneyGuard) {
        $manage($request);
        $input = $request->validate(['target' => $targetRule, 'file' => $fileRule, 'dry_run' => ['sometimes', 'boolean'], 'mapping' => ['required', 'array'], 'mapping.*' => ['nullable', 'integer', 'min:0']]);
        $moneyGuard($request, $input['target'], $input['mapping']);
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

        $result = $engine->run($input['target'], $sheet['rows'], $mapping, $request->boolean('dry_run'));
        if (! $request->boolean('dry_run')) {
            ProductionVolumes::sync(app(TenantContext::class)->companyId());
        }

        return ['data' => $result + ['dry_run' => $request->boolean('dry_run')]];
    })->name('production.imports.run');
});
