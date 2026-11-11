<?php

namespace App\Modules\Production\Support;

use App\Modules\Common\Support\ReferenceCodes;
use App\Modules\Identity\Authorization\AccessResolver;
use App\Modules\Production\Models\Article;
use App\Modules\Production\Models\CustomerOrder;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Production\Models\RawMaterial;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * What the API says about the production reference: each record with the names of what it points to, so a screen
 * never has to look them up (and never receives an internal id).
 *
 * Money (purchase prices, costs, selling prices, gain) is only given to who holds `production.finance.view`; for
 * anyone else those keys are null and `finance` is absent, so quantities and traceability stay readable without it.
 */
final class ProductionData
{
    /** How a raw material is counted when consumed. */
    public const MATERIAL_UNITS = ['m', 'kg', 'piece'];

    public const EXPENSE_CATEGORIES = ['subcontracting', 'external_service', 'purchase', 'transport', 'labour', 'other'];

    public const PRODUCED_LOT_STATUSES = ['produced', 'delivered', 'cancelled'];

    private const BATCH_COLUMNS = ['b.id', 'b.ulid', 'b.raw_material_id', 'b.batch_number', 'b.supplier_batch_reference', 'b.received_on', 'b.quantity', 'b.unit_price', 'b.total_amount',
        'b.delivery_cost', 'b.currency_code', 'b.tax_rate_pct', 'b.delivery_note', 'b.invoice_reference', 'b.external_reference', 'b.import_source', 'b.notes',
        's.ulid as supplier_ulid', 's.code as supplier_code', 's.name as supplier_name'];

    public function __construct(private readonly ReferenceCodes $codes, private readonly ProductionFinance $finance, private readonly AccessResolver $access, private readonly CuttingWaste $cutting, private readonly CuttingPlan $plan) {}

    /**
     * Stages a company may use: the defaults and its own.
     */
    public static function steps(int $companyId): Builder
    {
        return DB::table('production_steps')->where(fn (Builder $query) => $query->whereNull('company_id')->orWhere('company_id', $companyId));
    }

    /**
     * Whether the user of the request may read amounts.
     */
    public function money(): bool
    {
        return (bool) auth()->user()?->can('production.finance.view');
    }

    public function batches(): Builder
    {
        return DB::table('raw_material_batches as b')->leftJoin('production_suppliers as s', 's.id', '=', 'b.production_supplier_id')->select(self::BATCH_COLUMNS);
    }

    /**
     * A received lot: who delivered it, what it held, what all the orders together took from it and, for who may see
     * money, what it cost.
     *
     * @param  array<int, string>  $consumed  lot id => quantity consumed by every order
     * @return array<string, mixed>
     */
    public function batch(object $row, array $consumed = []): array
    {
        $taken = $consumed[$row->id] ?? '0.000';
        $unitCost = ProductionFinance::lotUnitCost($row);
        $money = $this->money();

        return [
            'ulid' => $row->ulid, 'batch_number' => $row->batch_number,
            // The supplier of this delivery — not necessarily the one the material proposes today.
            'supplier' => $row->supplier_ulid === null ? null : ['ulid' => $row->supplier_ulid, 'code' => $row->supplier_code, 'name' => $row->supplier_name],
            'supplier_batch_reference' => $row->supplier_batch_reference, 'received_on' => $row->received_on,
            'quantity' => $row->quantity === null ? null : (string) $row->quantity,
            'consumed' => bcadd($taken, '0', 3),
            // More was recorded as taken from the lot than it held: one of the two figures is wrong.
            'over_consumed' => $row->quantity !== null && bccomp($taken, (string) $row->quantity, 3) > 0,
            'delivery_note' => $row->delivery_note, 'invoice_reference' => $row->invoice_reference, 'external_reference' => $row->external_reference,
            'import_source' => $row->import_source, 'notes' => $row->notes,
            'unit_price' => $money && $row->unit_price !== null ? (string) $row->unit_price : null,
            'total_amount' => $money && $row->total_amount !== null ? (string) $row->total_amount : null,
            'delivery_cost' => $money && $row->delivery_cost !== null ? (string) $row->delivery_cost : null,
            'currency_code' => $money ? $row->currency_code : null,
            'tax_rate_pct' => $money && $row->tax_rate_pct !== null ? (string) $row->tax_rate_pct : null,
            // Per unit, delivery included; the purchase value is the whole delivery, the consumed value what orders took of it.
            'unit_cost' => $money && $unitCost !== null ? bcadd($unitCost, '0', 4) : null,
            'purchase_value' => $money ? ProductionFinance::lotPurchaseValue($row) : null,
            'consumed_value' => $money && $unitCost !== null ? bcmul($taken, $unitCost, 3) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function material(RawMaterial $material, bool $detailed = false): array
    {
        $rows = $this->batches()->where('b.raw_material_id', $material->id)->orderByDesc('b.received_on')->orderByDesc('b.id')->get();
        $consumed = $this->finance->consumedPerLot($rows->pluck('id')->all());
        $average = ProductionFinance::averageUnitCost($rows, $this->finance->currency());
        $waste = $this->cutting->applied((int) $material->id, $this->isFabric($material));
        $data = [
            'ulid' => $material->ulid, 'reference' => $material->reference, 'name' => $material->name, 'category' => $material->getAttribute('category'),
            // The supplier proposed for a new received lot; each lot keeps its own.
            'supplier' => $this->named('production_suppliers', $material->production_supplier_id),
            'unit' => $this->codes->code('units', $material->unit_id),
            'color_family' => $this->codes->code('color_families', $material->color_family_id), 'color_label' => $material->color_label,
            'grammage_gsm' => $material->grammage_gsm, 'width_cm' => $material->width_cm, 'composition' => $material->composition ?? [],
            // The waste it leaves, each type with its share: its own, or for a fabric the company's cutting scraps (inherited).
            'waste_types' => $this->wasteShares($waste['lines']),
            'waste_types_inherited' => $waste['inherited'],
            'notes' => $material->notes, 'is_active' => $material->is_active,
            'batches' => $rows->map(fn ($batch) => $this->batch($batch, $consumed)),
            'average_unit_cost' => $this->money() && $average !== null ? bcadd($average, '0', 4) : null,
        ];
        if (! $detailed) {
            return $data;
        }

        // Where the material went: the latest consumptions, each with its order and the lot it came from.
        $history = DB::table('production_order_consumptions as c')->join('production_orders as o', 'o.id', '=', 'c.production_order_id')->join('articles as a', 'a.id', '=', 'o.article_id')
            ->leftJoin('raw_material_batches as b', 'b.id', '=', 'c.raw_material_batch_id')->where('c.raw_material_id', $material->id)
            ->orderByDesc(DB::raw('COALESCE(c.consumed_on, o.started_on)'))->orderByDesc('c.id')->limit(200)
            ->get(['o.ulid as of', 'o.order_number', 'o.status', 'a.reference as article', 'b.id as batch_id', 'b.ulid as batch', 'b.batch_number', 'c.quantity', 'c.consumed_on', 'o.started_on']);
        $byId = $rows->keyBy('id');
        $totals = DB::table('production_order_consumptions')->where('raw_material_id', $material->id)
            ->selectRaw('COALESCE(SUM(quantity), 0) as consumed, COALESCE(SUM(CASE WHEN raw_material_batch_id IS NULL THEN quantity ELSE 0 END), 0) as unallocated, COUNT(DISTINCT production_order_id) as orders')->first();
        $waste = DB::table('waste_lots')->where('raw_material_id', $material->id)->whereNull('deleted_at')->where('origin_type', 'created')
            ->selectRaw('COUNT(*) as lots, COALESCE(SUM(net_weight_kg), 0) as kg')->first();

        return $data + [
            'summary' => [
                'received_lots' => $rows->count(),
                'received' => $rows->contains(fn ($batch) => $batch->quantity !== null) ? (string) $rows->reduce(fn (string $sum, $batch) => bcadd($sum, (string) ($batch->quantity ?? '0'), 3), '0') : null,
                'consumed' => (string) $totals->consumed, 'unallocated' => (string) $totals->unallocated, 'orders' => (int) $totals->orders,
                'waste_lots' => (int) $waste->lots, 'waste_kg' => (string) $waste->kg,
                'purchase_value' => $this->money() ? (string) $rows->reduce(fn (string $sum, $batch) => bcadd($sum, ProductionFinance::lotPurchaseValue($batch) ?? '0', 3), '0') : null,
                'unpriced_lots' => $this->money() ? $rows->filter(fn ($batch) => ProductionFinance::lotUnitCost($batch) === null)->count() : null,
            ],
            'consumptions' => $history->map(function ($row) use ($byId) {
                $unit = $row->batch_id === null || ! isset($byId[$row->batch_id]) ? null : ProductionFinance::lotUnitCost($byId[$row->batch_id]);

                return ['of' => $row->of, 'order_number' => $row->order_number, 'status' => $row->status, 'article' => $row->article, 'batch' => $row->batch, 'batch_number' => $row->batch_number,
                    'quantity' => (string) $row->quantity, 'date' => $row->consumed_on ?? $row->started_on,
                    'cost' => $this->money() && $unit !== null ? bcmul((string) $row->quantity, $unit, 3) : null];
            }),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function article(Article $article): array
    {
        $default = $this->cutting->defaultEfficiency();
        // What one piece is expected to consume, and for a fabric the waste its cutting is expected to leave.
        $materials = DB::table('article_materials as am')->join('raw_materials as m', 'm.id', '=', 'am.raw_material_id')->where('am.article_id', $article->id)->orderBy('am.id')
            ->get(['m.id', 'm.ulid', 'm.reference', 'm.name', 'm.unit_id', 'm.width_cm', 'm.grammage_gsm', 'm.composition', 'am.quantity_per_piece', 'am.efficiency_pct'])
            ->map(function ($row) use ($default) {
                $unit = $this->codes->code('units', (int) $row->unit_id);
                $cut = $this->cutting->line((string) $row->quantity_per_piece, $unit, $row->width_cm, $row->grammage_gsm, $row->efficiency_pct, $default, (int) $row->id);

                return ['material' => $row->ulid, 'reference' => $row->reference, 'name' => $row->name, 'unit' => $unit, 'quantity_per_piece' => (string) $row->quantity_per_piece,
                    'width_cm' => $row->width_cm === null ? null : (string) $row->width_cm, 'grammage_gsm' => $row->grammage_gsm === null ? null : (string) $row->grammage_gsm,
                    'composition' => $row->composition === null ? [] : json_decode((string) $row->composition, true),
                    // The waste it gives, by type: the material's shares of this line's waste per piece.
                    'waste_types' => $cut['waste_kg'] === null ? [] : array_map(fn (array $share) => ['waste_type' => $this->cutting->type($share['type'] ?: null), 'share_pct' => $share['pct'],
                        'waste_kg_per_piece' => CuttingWaste::round($cut['by_type'][$share['type']] ?? '0', 4)], $cut['shares']),
                    'waste_type_inherited' => $cut['inherited'], 'by_type_exact' => $cut['by_type'],
                    'efficiency_pct' => $cut['efficiency_pct'], 'efficiency_applied' => $cut['efficiency_applied'],
                    'fabric_kg_per_piece' => $cut['fabric_kg'] === null ? null : CuttingWaste::round($cut['fabric_kg'], 4), 'waste_kg_per_piece' => $cut['waste_kg'] === null ? null : CuttingWaste::round($cut['waste_kg'], 4),
                    'fabric_kg_exact' => $cut['fabric_kg'], 'waste_kg_exact' => $cut['waste_kg']];
            });
        $cut = $materials->filter(fn (array $line) => $line['waste_kg_exact'] !== null);
        $total = fn (string $key) => $cut->isEmpty() ? null : CuttingWaste::round($cut->reduce(fn (string $sum, array $line) => bcadd($sum, $line[$key], 6), '0'), 4);

        return [
            'ulid' => $article->ulid, 'reference' => $article->reference, 'name' => $article->name, 'family' => $article->family,
            'client' => $this->named('production_clients', $article->production_client_id),
            'notes' => $article->notes, 'is_active' => $article->is_active,
            'materials' => $materials->map(fn (array $line) => array_diff_key($line, ['fabric_kg_exact' => 0, 'waste_kg_exact' => 0, 'by_type_exact' => 0]))->values(),
            'cutting_waste' => [
                'default_efficiency_pct' => $default,
                'fabric_kg_per_piece' => $total('fabric_kg_exact'),
                'waste_kg_per_piece' => $total('waste_kg_exact'),
                // The same waste by the type it is (each line shared by its material's shares), the heaviest first.
                'by_type' => $cut->flatMap(fn (array $line) => collect($line['by_type_exact'])->map(fn (string $kg, int $type) => ['type' => $type, 'kg' => $kg])->values())
                    ->groupBy('type')
                    ->map(fn ($parts, $type) => ['waste_type' => $this->cutting->type((int) $type ?: null), 'lines' => $parts->count(),
                        'waste_kg_per_piece' => CuttingWaste::round($parts->reduce(fn (string $sum, array $part) => bcadd($sum, $part['kg'], 8), '0'), 4)])
                    ->sortByDesc(fn (array $group) => (float) $group['waste_kg_per_piece'])->values(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function order(CustomerOrder $order, bool $detailed = false): array
    {
        $money = $this->money();
        $currency = $order->getAttribute('currency_code') ?? $this->finance->currency();
        $lines = DB::table('customer_order_lines as l')->join('articles as a', 'a.id', '=', 'l.article_id')->where('l.customer_order_id', $order->id)->orderBy('l.id')
            ->get(['a.ulid', 'a.reference', 'a.name', 'l.quantity', 'l.unit_price']);
        $discount = (string) ($order->getAttribute('discount_amount') ?? '0');
        // The value of the order exists once every line has its price.
        $gross = $lines->isEmpty() || $lines->contains(fn ($line) => $line->unit_price === null) ? null
            : $lines->reduce(fn (string $sum, $line) => bcadd($sum, bcmul((string) $line->quantity, (string) $line->unit_price, 3), 3), '0');
        $value = $gross === null ? null : bcsub($gross, $discount, 3);
        $invoices = DB::table('customer_order_invoices as i')->leftJoin('production_orders as o', 'o.id', '=', 'i.production_order_id')->where('i.customer_order_id', $order->id)
            ->orderBy('i.issued_on')->orderBy('i.id')->get(['i.ulid', 'i.kind', 'i.number', 'i.issued_on', 'i.amount', 'i.currency_code', 'i.notes', 'o.ulid as of', 'o.order_number as of_number']);
        $invoiced = $invoices->where('currency_code', $currency)
            ->reduce(fn (string $sum, $invoice) => $invoice->kind === 'credit_note' ? bcsub($sum, (string) $invoice->amount, 3) : bcadd($sum, (string) $invoice->amount, 3), '0');

        $data = [
            'ulid' => $order->ulid, 'order_number' => $order->order_number, 'client' => $this->named('production_clients', $order->production_client_id),
            'ordered_on' => $order->ordered_on?->toDateString(), 'due_on' => $order->due_on?->toDateString(), 'status' => $order->status, 'notes' => $order->notes,
            'lines' => $lines->map(fn ($line) => ['article' => $line->ulid, 'reference' => $line->reference, 'name' => $line->name, 'quantity' => (int) $line->quantity,
                'unit_price' => $money && $line->unit_price !== null ? (string) $line->unit_price : null,
                'amount' => $money && $line->unit_price !== null ? bcmul((string) $line->quantity, (string) $line->unit_price, 3) : null]),
            'quantity' => (int) $lines->sum('quantity'),
            'manufacturing_orders' => DB::table('production_orders')->where('customer_order_id', $order->id)->count(),
            'currency_code' => $currency,
            'discount_amount' => $money && $order->getAttribute('discount_amount') !== null ? $discount : null,
            // What the client agreed to pay, and what was really invoiced so far: two different things.
            'order_value' => $money ? $value : null,
            'invoiced' => $money ? $invoiced : null,
            'uninvoiced' => $money && $value !== null ? bcsub($value, $invoiced, 3) : null,
        ];
        if (! $detailed) {
            return $data;
        }

        $ofs = ProductionOrder::query()->where('customer_order_id', $order->id)->orderBy('id')->get();
        $figures = $money ? $this->finance->forOrders($ofs->map(fn (ProductionOrder $of) => (object) $of->getAttributes())) : [];

        return $data + [
            'invoices' => ! $money ? [] : $invoices->map(fn ($invoice) => ['ulid' => $invoice->ulid, 'kind' => $invoice->kind, 'number' => $invoice->number, 'issued_on' => $invoice->issued_on,
                'amount' => (string) $invoice->amount, 'currency_code' => $invoice->currency_code, 'notes' => $invoice->notes,
                'manufacturing_order' => $invoice->of === null ? null : ['ulid' => $invoice->of, 'order_number' => $invoice->of_number]]),
            'orders' => $ofs->map(fn (ProductionOrder $of) => ['ulid' => $of->ulid, 'order_number' => $of->order_number, 'status' => $of->status,
                'article' => DB::table('articles')->where('id', $of->article_id)->value('reference'),
                'quantity_planned' => $of->quantity_planned, 'quantity_produced' => $of->quantity_produced,
                'total_cost' => $figures[$of->id]['total_cost'] ?? null, 'cost_complete' => $figures[$of->id]['cost_complete'] ?? null,
                'produced_value' => $figures[$of->id]['revenue']['produced_value'] ?? null, 'gain' => $figures[$of->id]['gain']['amount'] ?? null]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function manufacturingOrder(ProductionOrder $of, bool $detailed = false): array
    {
        $article = DB::table('articles')->where('id', $of->article_id)->first(['ulid', 'reference', 'name', 'production_client_id']);
        $order = $of->customer_order_id === null ? null : DB::table('customer_orders')->where('id', $of->customer_order_id)->first(['ulid', 'order_number', 'production_client_id']);
        $steps = DB::table('production_order_steps as s')->join('production_steps as p', 'p.id', '=', 's.production_step_id')->join('sites', 'sites.id', '=', 's.site_id')
            ->where('s.production_order_id', $of->id)->orderBy('s.sequence')
            ->get(['p.code', 'p.name', 'sites.ulid as site', 'sites.name as site_name', 's.pieces_done', 's.started_on', 's.finished_on'])
            ->map(fn ($step) => ['step' => $step->code, 'name' => json_decode((string) $step->name, true), 'site' => $step->site, 'site_name' => $step->site_name,
                'pieces_done' => $step->pieces_done === null ? null : (int) $step->pieces_done, 'started_on' => $step->started_on, 'finished_on' => $step->finished_on]);

        $data = [
            'ulid' => $of->ulid, 'order_number' => $of->order_number,
            'article' => ['ulid' => $article->ulid, 'reference' => $article->reference, 'name' => $article->name],
            'customer_order' => $order === null ? null : ['ulid' => $order->ulid, 'order_number' => $order->order_number],
            // The client of the order, or the client the article is made for.
            'client' => $this->named('production_clients', $order->production_client_id ?? $article->production_client_id),
            'quantity_planned' => $of->quantity_planned, 'quantity_produced' => $of->quantity_produced,
            // The sizes it makes, in the order of its grid, and how many of each.
            'size_grid' => ($grid = $of->getAttribute('size_grid_id') === null ? null : DB::table('size_grids')->where('id', $of->getAttribute('size_grid_id'))->first(['ulid', 'name', 'sizes'])) === null
                ? null : ['ulid' => $grid->ulid, 'name' => $grid->name, 'sizes' => json_decode((string) $grid->sizes, true)],
            'sizes' => $of->size_quantities ?? [],
            'started_on' => $of->started_on?->toDateString(), 'finished_on' => $of->finished_on?->toDateString(), 'status' => $of->status, 'notes' => $of->notes,
            'steps' => $steps,
        ];
        if (! $detailed) {
            return $data;
        }

        $money = $this->money();
        $figures = $this->finance->forOrders([(object) $of->getAttributes()])[$of->id];
        $moneyKeys = ['average_unit_cost', 'planned_cost', 'cost_known', 'cost_estimated', 'cost'];
        // Planned against consumed, material by material, each consumption with the received lot and the supplier it comes from.
        $materials = array_map(function (array $row) use ($money, $moneyKeys) {
            if (! $money) {
                $row = array_replace($row, array_fill_keys($moneyKeys, null));
                $row['lines'] = array_map(fn (array $line) => array_replace($line, ['unit_cost' => null, 'cost' => null, 'basis' => null, 'reason' => null]), $row['lines']);
            }

            return $row;
        }, $figures['materials']);

        $produced = $this->producedLots()->where('p.production_order_id', $of->id)->orderBy('p.produced_on')->orderBy('p.id')->get();
        unset($figures['materials']);
        $cutting = $this->plan->forOrder($of);

        return $data + [
            'materials' => $materials,
            // The same lines flat, as the edit form sends them back.
            'consumptions' => collect($materials)->flatMap(fn (array $row) => array_map(fn (array $line) => ['material' => $row['material'], 'reference' => $row['reference'], 'name' => $row['name'],
                'unit' => $row['unit'], 'batch' => $line['batch'], 'batch_number' => $line['batch_number'], 'quantity' => $line['quantity'], 'step' => $line['step'], 'site' => $line['site'],
                'consumed_on' => $line['consumed_on'], 'notes' => $line['notes']], $row['lines']))->values(),
            'planned_consumption' => collect($materials)->filter(fn (array $row) => $row['planned'] !== null)
                ->map(fn (array $row) => ['material' => $row['material'], 'reference' => $row['reference'], 'name' => $row['name'], 'unit' => $row['unit'], 'planned' => $row['planned'], 'consumed' => $row['consumed']])->values(),
            'produced_lots' => $produced->map(fn ($lot) => $this->producedLot($lot)),
            'produced' => $figures['produced'],
            'waste' => $this->waste($of) + ['estimated' => $this->estimatedWaste($of, $cutting)],
            'cutting' => array_diff_key($cutting, ['by_type' => 0]),
            'expenses' => ! $money ? [] : DB::table('production_order_expenses as e')->leftJoin('production_suppliers as s', 's.id', '=', 'e.production_supplier_id')
                ->where('e.production_order_id', $of->id)->orderBy('e.incurred_on')->orderBy('e.id')
                ->get(['e.ulid', 'e.category', 'e.description', 'e.amount', 'e.currency_code', 'e.incurred_on', 'e.reference', 'e.notes', 's.ulid as supplier', 's.name as supplier_name'])
                ->map(fn ($expense) => ['ulid' => $expense->ulid, 'category' => $expense->category, 'description' => $expense->description, 'amount' => (string) $expense->amount,
                    'currency_code' => $expense->currency_code, 'incurred_on' => $expense->incurred_on, 'reference' => $expense->reference, 'notes' => $expense->notes,
                    'supplier' => $expense->supplier === null ? null : ['ulid' => $expense->supplier, 'name' => $expense->supplier_name]]),
            'finance' => $money ? $figures : null,
        ];
    }

    public function producedLots(): Builder
    {
        return DB::table('produced_lots as p')->join('production_orders as o', 'o.id', '=', 'p.production_order_id')->join('articles as a', 'a.id', '=', 'o.article_id')
            ->leftJoin('customer_orders as c', 'c.id', '=', 'o.customer_order_id')
            ->leftJoin('production_clients as cl', fn ($join) => $join->on('cl.id', '=', DB::raw('COALESCE(c.production_client_id, a.production_client_id)')))
            ->leftJoin('production_steps as st', 'st.id', '=', 'p.production_step_id')->leftJoin('sites', 'sites.id', '=', 'p.site_id')->join('units as u', 'u.id', '=', 'p.unit_id')
            ->select(['p.id', 'p.ulid', 'p.lot_number', 'p.quantity', 'u.code as unit', 'p.produced_on', 'p.produced_until', 'p.status', 'p.delivered_on', 'p.notes', 'p.external_reference',
                'st.code as step', 'st.name as step_name', 'sites.ulid as site', 'sites.name as site_name', 'o.ulid as of', 'o.order_number as of_number',
                'a.reference as article', 'a.name as article_name', 'c.ulid as customer_order', 'c.order_number as customer_order_number', 'cl.ulid as client', 'cl.name as client_name']);
    }

    /**
     * A produced lot with the OF, the article, the order and the client it was made for. No supplier: that is for received lots.
     *
     * @return array<string, mixed>
     */
    public function producedLot(object $lot): array
    {
        return [
            'ulid' => $lot->ulid, 'lot_number' => $lot->lot_number, 'quantity' => (int) $lot->quantity, 'unit' => $lot->unit,
            'produced_on' => $lot->produced_on, 'produced_until' => $lot->produced_until, 'status' => $lot->status, 'delivered_on' => $lot->delivered_on,
            'step' => $lot->step, 'step_name' => $lot->step_name === null ? null : json_decode((string) $lot->step_name, true), 'site' => $lot->site, 'site_name' => $lot->site_name,
            'notes' => $lot->notes, 'external_reference' => $lot->external_reference,
            'manufacturing_order' => ['ulid' => $lot->of, 'order_number' => $lot->of_number],
            'article' => ['reference' => $lot->article, 'name' => $lot->article_name],
            'customer_order' => $lot->customer_order === null ? null : ['ulid' => $lot->customer_order, 'order_number' => $lot->customer_order_number],
            'client' => $lot->client === null ? null : ['ulid' => $lot->client, 'name' => $lot->client_name],
        ];
    }

    /**
     * The waste lots that name this OF. Totals are those of the OF; the lots themselves are listed for the sites where
     * the user may read lots — the waste register and its rights are not bypassed from here.
     *
     * @return array<string, mixed>
     */
    private function waste(ProductionOrder $of): array
    {
        $base = fn () => DB::table('waste_lots as l')->where('l.production_order_id', $of->id)->whereNull('l.deleted_at');
        $totals = $base()->where('l.origin_type', 'created')->selectRaw('COUNT(*) as lots, COALESCE(SUM(l.net_weight_kg), 0) as kg, COALESCE(SUM(l.net_weight_kg IS NULL), 0) as unweighed')->first();
        $user = auth()->user();
        $siteIds = $user === null ? [] : $this->access->siteIdsFor($user, 'lots.view');
        $rows = $siteIds === [] ? collect() : $base()->when($siteIds !== null, fn (Builder $query) => $query->whereIn('l.site_id', $siteIds))
            ->join('waste_types as t', 't.id', '=', 'l.waste_type_id')->join('sites', 'sites.id', '=', 'l.site_id')
            ->leftJoin('production_steps as st', 'st.id', '=', 'l.production_step_id')->leftJoin('raw_materials as m', 'm.id', '=', 'l.raw_material_id')
            ->leftJoin('raw_material_batches as b', 'b.id', '=', 'l.raw_material_batch_id')
            ->orderByDesc('l.generated_at')->limit(200)
            ->get(['l.ulid', 'l.lot_number', 'l.status', 'l.net_weight_kg', 'l.generated_at', 'l.origin_type', 't.name as waste_type', 'sites.name as site_name',
                'st.name as step_name', 'm.reference as material', 'b.batch_number']);
        // What the OF consumed, in kilograms when every line can be converted: the only sound denominator of a waste rate.
        $consumedKg = '0';
        $lines = DB::table('production_order_consumptions as c')->join('raw_materials as m', 'm.id', '=', 'c.raw_material_id')->join('units as u', 'u.id', '=', 'm.unit_id')
            ->where('c.production_order_id', $of->id)->get(['c.quantity', 'u.code as unit', 'm.width_cm', 'm.grammage_gsm']);
        foreach ($lines as $line) {
            $one = match (true) {
                $line->unit === 'kg' => (string) $line->quantity,
                $line->unit === 'm' && $line->width_cm !== null && $line->grammage_gsm !== null => bcdiv(bcmul(bcmul((string) $line->quantity, (string) $line->width_cm, 6), (string) $line->grammage_gsm, 6), '100000', 3),
                default => null,
            };
            $consumedKg = $consumedKg === null || $one === null ? null : bcadd($consumedKg, $one, 3);
        }
        $consumedKg = $lines->isEmpty() || $consumedKg === null || bccomp($consumedKg, '0', 3) <= 0 ? null : $consumedKg;

        return [
            'lots' => (int) $totals->lots, 'kg' => (string) $totals->kg, 'unweighed_lots' => (int) $totals->unweighed,
            'rows' => $rows->map(fn ($lot) => ['ulid' => $lot->ulid, 'lot_number' => $lot->lot_number, 'status' => $lot->status, 'net_weight_kg' => $lot->net_weight_kg === null ? null : (string) $lot->net_weight_kg,
                'generated_at' => $lot->generated_at, 'origin_type' => $lot->origin_type, 'waste_type' => $lot->waste_type, 'site_name' => $lot->site_name,
                'step_name' => $lot->step_name === null ? null : json_decode((string) $lot->step_name, true),
                // The OF says where the waste comes from; the material and the lot only when the operator said them.
                'material' => $lot->material, 'batch_number' => $lot->batch_number]),
            'can_view_lots' => $siteIds !== [],
            'consumed_kg' => $consumedKg,
            // Only from what was weighed: lots waiting for their weight are not a rate of 0 %.
            'waste_rate_pct' => $consumedKg === null || bccomp((string) $totals->kg, '0', 3) <= 0 ? null : round((float) $totals->kg / (float) $consumedKg * 100, 1),
        ];
    }

    /**
     * The cutting waste the OF is expected to leave: what one piece of its article leaves, for the pieces planned
     * (and for those produced, once some are). Null when no fabric of the article has its laize and grammage.
     *
     * By type of waste, the estimate is set beside the lots of the OF of that same type only: packaging or threads
     * weighed on the OF are not cutting scraps.
     *
     * Once the OF has its spreads (matelas), they say it instead (source "cutting"): what the layers planned leave, then
     * what the layers really cut leave, with the efficiency of each placement; per piece, for the pieces of the OF.
     *
     * @param  array<string, mixed>|null  $cutting
     * @return array<string, mixed>|null
     */
    private function estimatedWaste(ProductionOrder $of, ?array $cutting = null): ?array
    {
        $weighed = DB::table('waste_lots')->where('production_order_id', $of->id)->whereNull('deleted_at')->where('origin_type', 'created')->groupBy('waste_type_id')
            ->selectRaw('waste_type_id, COUNT(*) as lots, COALESCE(SUM(net_weight_kg), 0) as kg, COALESCE(SUM(net_weight_kg IS NULL), 0) as unweighed')->get()->keyBy('waste_type_id');
        $against = fn (int $typeId) => [
            'weighed_kg' => $typeId === 0 || ! isset($weighed[$typeId]) ? null : bcadd((string) $weighed[$typeId]->kg, '0', 3),
            'lots' => $typeId === 0 ? 0 : (int) ($weighed[$typeId]->lots ?? 0),
            'unweighed_lots' => $typeId === 0 ? 0 : (int) ($weighed[$typeId]->unweighed ?? 0),
        ];
        if ($cutting !== null && ($cutting['totals']['planned']['waste_kg'] ?? null) !== null) {
            $pieces = (int) $of->quantity_planned;
            $done = $cutting['totals']['done'];

            return [
                'source' => 'cutting',
                'per_piece_kg' => $pieces > 0 ? CuttingWaste::round(bcdiv($cutting['totals']['planned']['waste_kg'], (string) $pieces, 8), 4) : null,
                'planned_kg' => $cutting['totals']['planned']['waste_kg'], 'planned_pieces' => $pieces,
                'produced_kg' => $done['waste_kg'] ?? null, 'produced_pieces' => $of->quantity_produced === null ? null : (int) $of->quantity_produced,
                'by_type' => collect($cutting['by_type'])->filter(fn (array $row) => $row['planned_kg'] !== null)->map(fn (array $row) => [
                    'waste_type' => $this->cutting->type($row['type_id'] === 0 ? null : $row['type_id']),
                    'per_piece_kg' => $pieces > 0 ? CuttingWaste::round(bcdiv($row['planned_kg'], (string) $pieces, 8), 4) : null,
                    'planned_kg' => CuttingWaste::round($row['planned_kg'], 3), 'produced_kg' => $row['done_kg'] === null ? null : CuttingWaste::round($row['done_kg'], 3),
                ] + $against($row['type_id']))->sortByDesc(fn (array $row) => (float) $row['planned_kg'])->values()->all(),
            ];
        }
        $piece = $this->cutting->perPiece([(int) $of->article_id])[(int) $of->article_id] ?? null;
        if ($piece === null) {
            return null;
        }
        $times = fn (string $kg, ?int $pieces) => $pieces === null ? null : CuttingWaste::round(bcmul($kg, (string) $pieces, 6), 3);
        $produced = $of->quantity_produced === null ? null : (int) $of->quantity_produced;
        $weighed = DB::table('waste_lots')->where('production_order_id', $of->id)->whereNull('deleted_at')->where('origin_type', 'created')->groupBy('waste_type_id')
            ->selectRaw('waste_type_id, COUNT(*) as lots, COALESCE(SUM(net_weight_kg), 0) as kg, COALESCE(SUM(net_weight_kg IS NULL), 0) as unweighed')->get()->keyBy('waste_type_id');

        return [
            'source' => 'article',
            'per_piece_kg' => CuttingWaste::round($piece['waste_kg'], 4),
            'planned_kg' => $times($piece['waste_kg'], (int) $of->quantity_planned), 'planned_pieces' => (int) $of->quantity_planned,
            'produced_kg' => $times($piece['waste_kg'], $produced), 'produced_pieces' => $produced,
            'by_type' => collect($piece['by_type'])->map(fn (string $kg, int $typeId) => [
                'waste_type' => $this->cutting->type($typeId === 0 ? null : $typeId),
                'per_piece_kg' => CuttingWaste::round($kg, 4),
                'planned_kg' => $times($kg, (int) $of->quantity_planned),
                'produced_kg' => $times($kg, $produced),
                // What was weighed of that type on the OF; lots not yet weighed are counted apart, never as 0 kg.
                'weighed_kg' => $typeId === 0 || ! isset($weighed[$typeId]) ? null : bcadd((string) $weighed[$typeId]->kg, '0', 3),
                'lots' => $typeId === 0 ? 0 : (int) ($weighed[$typeId]->lots ?? 0),
                'unweighed_lots' => $typeId === 0 ? 0 : (int) ($weighed[$typeId]->unweighed ?? 0),
            ])->sortByDesc(fn (array $row) => (float) $row['per_piece_kg'])->values()->all(),
        ];
    }

    /**
     * @param  list<array{type: int, pct: string}>  $lines
     * @return list<array{waste_type: array{ulid: string, name: string}|null, share_pct: string}>
     */
    private function wasteShares(array $lines): array
    {
        return array_map(fn (array $line) => ['waste_type' => $this->cutting->type($line['type'] ?: null), 'share_pct' => $line['pct']], $lines);
    }

    /** A fabric is cut from a placement: counted in metres, with its laize and its grammage. */
    private function isFabric(RawMaterial $material): bool
    {
        return $this->codes->code('units', (int) $material->unit_id) === 'm' && $material->width_cm !== null && $material->grammage_gsm !== null;
    }

    /**
     * @return array{ulid: string, code: string, name: string}|null
     */
    private function named(string $table, ?int $id): ?array
    {
        $row = $id === null ? null : DB::table($table)->where('id', $id)->first(['ulid', 'code', 'name']);

        return $row === null ? null : (array) $row;
    }
}
