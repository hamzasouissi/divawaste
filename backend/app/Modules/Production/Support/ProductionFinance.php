<?php

namespace App\Modules\Production\Support;

use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What a manufacturing order cost and what it brings in, from what was recorded — and only from that.
 *
 * COST OF MATERIALS. A consumption that names its received lot costs `quantity × unit cost of that lot` (the price
 * paid for that delivery, its delivery cost spread over its quantity): the actual cost. A lot used by several orders
 * is therefore charged to each one for what it consumed, never in full. A consumption whose lot is not known is
 * ESTIMATED at the average price of the priced lots of its material, weighted by their quantities, and counted apart.
 * A consumption that has neither is UNKNOWN: it is reported as missing, never counted as zero.
 *
 * REVENUE. The price is the one agreed on the order line of the article. The value of the production is
 * `pieces made × that price`, less the share of the discount of the order. What was really invoiced is the invoices
 * recorded for the order, less its credit notes; it belongs to an OF only when the invoice names it or when the order
 * has a single OF — otherwise it stays at the level of the order.
 *
 * GAIN. `value of the production − recorded costs (materials + other expenses)`. A gross figure on recorded costs:
 * no overheads, no labour unless entered as an expense. It is given only when the price, the production and the cost
 * of every consumption are known.
 *
 * Amounts are excluding tax, in the currency of the company; an amount in another currency is left out and reported.
 */
final class ProductionFinance
{
    public function __construct(private readonly TenantContext $context) {}

    public function currency(): string
    {
        return (string) DB::table('companies')->where('id', $this->context->companyId())->value('currency_code');
    }

    /**
     * What one unit of a received lot cost, delivery included when it can be spread.
     */
    public static function lotUnitCost(object $batch): ?string
    {
        $quantity = $batch->quantity !== null && bccomp((string) $batch->quantity, '0', 3) > 0 ? (string) $batch->quantity : null;
        $base = $batch->unit_price !== null ? (string) $batch->unit_price : ($batch->total_amount !== null && $quantity !== null ? bcdiv((string) $batch->total_amount, $quantity, 6) : null);
        if ($base === null) {
            return null;
        }

        return $batch->delivery_cost !== null && $quantity !== null ? bcadd($base, bcdiv((string) $batch->delivery_cost, $quantity, 6), 6) : bcadd($base, '0', 6);
    }

    /**
     * Quantity, unit price and total say the same thing twice: the missing one is computed, and two that disagree are
     * refused (null) rather than one of them silently trusted.
     *
     * @return array{unit_price: string|null, total_amount: string|null}|null
     */
    public static function reconcile(?string $quantity, ?string $unitPrice, ?string $total): ?array
    {
        $hasQuantity = $quantity !== null && bccomp($quantity, '0', 3) > 0;
        if ($hasQuantity && $unitPrice !== null && $total !== null) {
            $expected = bcmul($quantity, $unitPrice, 3);
            $gap = abs((float) bcsub($expected, $total, 3));

            return $gap > max(0.01, 0.005 * (float) $total) ? null : ['unit_price' => $unitPrice, 'total_amount' => $total];
        }

        return [
            'unit_price' => $unitPrice ?? ($hasQuantity && $total !== null ? bcdiv($total, $quantity, 4) : null),
            'total_amount' => $total ?? ($hasQuantity && $unitPrice !== null ? bcmul($quantity, $unitPrice, 3) : null),
        ];
    }

    /**
     * What the delivery was bought for, delivery cost included: the purchase value, which is not the cost of any order.
     */
    public static function lotPurchaseValue(object $batch): ?string
    {
        $value = $batch->total_amount !== null ? (string) $batch->total_amount
            : ($batch->unit_price !== null && $batch->quantity !== null ? bcmul((string) $batch->unit_price, (string) $batch->quantity, 3) : null);

        return $value === null ? null : bcadd($value, (string) ($batch->delivery_cost ?? '0'), 3);
    }

    /**
     * The average price of a material over its priced lots, weighted by their quantities (lots without a quantity
     * weigh one unit each). Null when no lot of the material has a price in the currency of the company.
     *
     * @param  Collection<int, \stdClass>  $batches  the lots of one material
     */
    public static function averageUnitCost(Collection $batches, string $currency): ?string
    {
        $value = '0';
        $weight = '0';
        foreach ($batches as $batch) {
            $unit = ($batch->currency_code ?? $currency) === $currency ? self::lotUnitCost($batch) : null;
            if ($unit === null) {
                continue;
            }
            $quantity = $batch->quantity !== null && bccomp((string) $batch->quantity, '0', 3) > 0 ? (string) $batch->quantity : '1';
            $value = bcadd($value, bcmul($unit, $quantity, 6), 6);
            $weight = bcadd($weight, $quantity, 3);
        }

        return bccomp($weight, '0', 3) > 0 ? bcdiv($value, $weight, 6) : null;
    }

    /**
     * @param  Collection<int, \stdClass>|list<\stdClass>  $orders  rows of production_orders (id, article_id, customer_order_id, quantity_planned, quantity_produced, status)
     * @return array<int, array<string, mixed>> order id => figures
     */
    public function forOrders($orders): array
    {
        $orders = collect($orders)->keyBy('id');
        if ($orders->isEmpty()) {
            return [];
        }
        $companyId = $this->context->companyId();
        $currency = $this->currency();
        $ids = $orders->keys()->all();

        $consumptions = DB::table('production_order_consumptions as c')
            ->leftJoin('production_steps as st', 'st.id', '=', 'c.production_step_id')->leftJoin('sites', 'sites.id', '=', 'c.site_id')
            ->where('c.company_id', $companyId)->whereIn('c.production_order_id', $ids)->orderBy('c.id')
            ->get(['c.id', 'c.production_order_id', 'c.raw_material_id', 'c.raw_material_batch_id', 'c.quantity', 'c.consumed_on', 'c.notes',
                'st.code as step', 'st.name as step_name', 'sites.ulid as site', 'sites.name as site_name'])->groupBy('production_order_id');
        $planned = DB::table('article_materials')->where('company_id', $companyId)->whereIn('article_id', $orders->pluck('article_id')->unique())
            ->get(['article_id', 'raw_material_id', 'quantity_per_piece'])->groupBy('article_id');
        $materialIds = $consumptions->flatten(1)->pluck('raw_material_id')->merge($planned->flatten(1)->pluck('raw_material_id'))->unique()->values();
        $materials = DB::table('raw_materials as m')->join('units as u', 'u.id', '=', 'm.unit_id')->whereIn('m.id', $materialIds)
            ->get(['m.id', 'm.ulid', 'm.reference', 'm.name', 'u.code as unit'])->keyBy('id');
        $batches = DB::table('raw_material_batches as b')->leftJoin('production_suppliers as s', 's.id', '=', 'b.production_supplier_id')
            ->where('b.company_id', $companyId)->whereIn('b.raw_material_id', $materialIds)
            ->get(['b.id', 'b.ulid', 'b.raw_material_id', 'b.batch_number', 'b.quantity', 'b.unit_price', 'b.total_amount', 'b.delivery_cost', 'b.currency_code', 's.ulid as supplier', 's.name as supplier_name']);
        $batchById = $batches->keyBy('id');
        $average = $batches->groupBy('raw_material_id')->map(fn (Collection $lots) => self::averageUnitCost($lots, $currency));

        $expenses = DB::table('production_order_expenses')->where('company_id', $companyId)->whereIn('production_order_id', $ids)
            ->get(['production_order_id', 'category', 'amount', 'currency_code'])->groupBy('production_order_id');
        $produced = DB::table('produced_lots')->where('company_id', $companyId)->whereIn('production_order_id', $ids)->where('status', '!=', 'cancelled')
            ->get(['production_order_id', 'quantity', 'status'])->groupBy('production_order_id');

        $orderIds = $orders->pluck('customer_order_id')->filter()->unique()->values();
        $customerOrders = DB::table('customer_orders')->whereIn('id', $orderIds)->get(['id', 'ulid', 'order_number', 'currency_code', 'discount_amount'])->keyBy('id');
        $lines = DB::table('customer_order_lines')->whereIn('customer_order_id', $orderIds)->get(['customer_order_id', 'article_id', 'quantity', 'unit_price'])->groupBy('customer_order_id');
        $invoices = DB::table('customer_order_invoices')->where('company_id', $companyId)->whereIn('customer_order_id', $orderIds)
            ->get(['customer_order_id', 'production_order_id', 'kind', 'amount', 'currency_code'])->groupBy('customer_order_id');
        // How many live OFs each order has: an invoice of the order belongs to its OF only when there is a single one.
        $siblings = DB::table('production_orders')->where('company_id', $companyId)->whereIn('customer_order_id', $orderIds)->where('status', '!=', 'cancelled')
            ->groupBy('customer_order_id')->pluck(DB::raw('COUNT(*)'), 'customer_order_id');

        $result = [];
        foreach ($orders as $order) {
            $id = (int) $order->id;
            $warnings = [];

            // --- Materials: planned against consumed, each consumption with where its cost comes from.
            $rows = [];
            foreach ($planned[$order->article_id] ?? [] as $line) {
                $rows[(int) $line->raw_material_id] = ['planned' => bcmul((string) $line->quantity_per_piece, (string) $order->quantity_planned, 3), 'lines' => []];
            }
            foreach ($consumptions[$id] ?? [] as $line) {
                $rows[(int) $line->raw_material_id] ??= ['planned' => null, 'lines' => []];
                $rows[(int) $line->raw_material_id]['lines'][] = $line;
            }
            $known = '0.000';
            $estimated = '0.000';
            $unknownLines = 0;
            $plannedCost = '0.000';
            $materialRows = [];
            foreach ($rows as $materialId => $row) {
                $material = $materials[$materialId];
                $materialAverage = $average[$materialId] ?? null;
                $consumed = '0.000';
                $allocated = '0.000';
                $rowKnown = '0.000';
                $rowEstimated = '0.000';
                $rowUnknown = '0.000';
                $detail = [];
                foreach ($row['lines'] as $line) {
                    $quantity = (string) $line->quantity;
                    $batch = $line->raw_material_batch_id === null ? null : ($batchById[$line->raw_material_batch_id] ?? null);
                    $consumed = bcadd($consumed, $quantity, 3);
                    [$unit, $basis, $reason] = [null, 'unknown', 'no_price'];
                    if ($batch !== null) {
                        $allocated = bcadd($allocated, $quantity, 3);
                        if (($batch->currency_code ?? $currency) !== $currency) {
                            $reason = 'currency';
                        } elseif (($unit = self::lotUnitCost($batch)) !== null) {
                            [$basis, $reason] = ['lot', null];
                        }
                    } elseif ($materialAverage !== null) {
                        // The lot is not known: nothing is invented about it, the usual price of the material is used and said.
                        [$unit, $basis, $reason] = [$materialAverage, 'average', null];
                    }
                    $cost = $unit === null ? null : bcmul($quantity, $unit, 3);
                    match ($basis) {
                        'lot' => $rowKnown = bcadd($rowKnown, (string) $cost, 3),
                        'average' => $rowEstimated = bcadd($rowEstimated, (string) $cost, 3),
                        default => $rowUnknown = bcadd($rowUnknown, $quantity, 3),
                    };
                    $unknownLines += $basis === 'unknown' ? 1 : 0;
                    $detail[] = [
                        'batch' => $batch?->ulid, 'batch_number' => $batch?->batch_number,
                        'supplier' => $batch === null || $batch->supplier === null ? null : ['ulid' => $batch->supplier, 'name' => $batch->supplier_name],
                        'quantity' => $quantity, 'step' => $line->step, 'step_name' => $line->step_name === null ? null : json_decode((string) $line->step_name, true),
                        'site' => $line->site, 'site_name' => $line->site_name, 'consumed_on' => $line->consumed_on, 'notes' => $line->notes,
                        'unit_cost' => $unit === null ? null : bcadd($unit, '0', 4), 'cost' => $cost, 'basis' => $basis, 'reason' => $reason,
                    ];
                }
                $known = bcadd($known, $rowKnown, 3);
                $estimated = bcadd($estimated, $rowEstimated, 3);
                $rowPlannedCost = $row['planned'] === null || $materialAverage === null ? null : bcmul($row['planned'], $materialAverage, 3);
                $plannedCost = $plannedCost === null || ($row['planned'] !== null && $rowPlannedCost === null) ? null : bcadd($plannedCost, $rowPlannedCost ?? '0', 3);
                $variance = $row['planned'] === null || $row['lines'] === [] ? null : bcsub($consumed, $row['planned'], 3);
                $materialRows[] = [
                    'material' => $material->ulid, 'reference' => $material->reference, 'name' => $material->name, 'unit' => $material->unit,
                    'planned' => $row['planned'], 'consumed' => $consumed, 'variance' => $variance,
                    'variance_pct' => $variance === null || bccomp((string) $row['planned'], '0', 3) <= 0 ? null : round((float) $variance / (float) $row['planned'] * 100, 1),
                    // How much of what was consumed names its received lot.
                    'allocated' => $allocated, 'unallocated' => bcsub($consumed, $allocated, 3),
                    'average_unit_cost' => $materialAverage === null ? null : bcadd($materialAverage, '0', 4),
                    'planned_cost' => $rowPlannedCost, 'cost_known' => $rowKnown, 'cost_estimated' => $rowEstimated, 'unknown_quantity' => $rowUnknown,
                    'cost' => bccomp($rowUnknown, '0', 3) > 0 ? null : bcadd($rowKnown, $rowEstimated, 3),
                    'lines' => $detail,
                ];
            }
            $hasPlan = isset($planned[$order->article_id]);
            $hasConsumption = isset($consumptions[$id]);
            if ($unknownLines > 0) {
                $warnings[] = 'unknown_material_cost';
            }
            if ($hasPlan && ! $hasConsumption) {
                $warnings[] = 'no_consumption';
            }
            if (bccomp($estimated, '0', 3) > 0) {
                $warnings[] = 'estimated_material_cost';
            }
            $materialComplete = $unknownLines === 0 && ($hasConsumption || ! $hasPlan);

            // --- Other expenses.
            $expenseTotal = '0.000';
            $byCategory = [];
            $excluded = 0;
            foreach ($expenses[$id] ?? [] as $expense) {
                if ($expense->currency_code !== $currency) {
                    $excluded++;

                    continue;
                }
                $expenseTotal = bcadd($expenseTotal, (string) $expense->amount, 3);
                $byCategory[$expense->category] = bcadd($byCategory[$expense->category] ?? '0', (string) $expense->amount, 3);
            }
            if ($excluded > 0) {
                $warnings[] = 'expense_currency';
            }
            $materialTotal = bcadd($known, $estimated, 3);
            $totalCost = bcadd($materialTotal, $expenseTotal, 3);
            $costComplete = $materialComplete && $excluded === 0;

            // --- Production: the produced lots when there are some, otherwise the figure typed on the order.
            $lots = $produced[$id] ?? collect();
            $quantity = $lots->isNotEmpty() ? (int) $lots->sum('quantity') : ($order->quantity_produced === null ? null : (int) $order->quantity_produced);
            $delivered = (int) $lots->where('status', 'delivered')->sum('quantity');

            // --- Revenue.
            $customerOrder = $order->customer_order_id === null ? null : ($customerOrders[$order->customer_order_id] ?? null);
            $orderLines = $customerOrder === null ? collect() : ($lines[$customerOrder->id] ?? collect());
            $line = $orderLines->firstWhere('article_id', $order->article_id);
            $revenue = ['unit_price' => null, 'net_unit_price' => null, 'planned_value' => null, 'produced_value' => null, 'delivered_value' => null,
                'order' => null, 'order_value' => null, 'invoiced' => null, 'invoiced_scope' => null, 'uninvoiced' => null];
            $revenueMissing = null;
            if ($customerOrder === null) {
                $revenueMissing = 'no_order';
            } elseif (($customerOrder->currency_code ?? $currency) !== $currency) {
                $revenueMissing = 'order_currency';
            } else {
                $revenue['order'] = ['ulid' => $customerOrder->ulid, 'order_number' => $customerOrder->order_number];
                // The value of the whole order, when every line has its price.
                $gross = $orderLines->contains(fn ($l) => $l->unit_price === null) || $orderLines->isEmpty() ? null
                    : $orderLines->reduce(fn (string $sum, $l) => bcadd($sum, bcmul((string) $l->quantity, (string) $l->unit_price, 3), 3), '0');
                $discount = (string) ($customerOrder->discount_amount ?? '0');
                $revenue['order_value'] = $gross === null ? null : bcsub($gross, $discount, 3);
                if ($line === null || $line->unit_price === null) {
                    $revenueMissing = 'no_price';
                } else {
                    $price = (string) $line->unit_price;
                    // The discount of the order is spread over its lines in proportion to their value.
                    $net = $gross !== null && bccomp($gross, '0', 3) > 0 ? bcmul($price, bcsub('1', bcdiv($discount, $gross, 8), 8), 6) : $price;
                    $revenue['unit_price'] = bcadd($price, '0', 4);
                    $revenue['net_unit_price'] = bcadd($net, '0', 4);
                    $revenue['planned_value'] = bcmul((string) $order->quantity_planned, $net, 3);
                    $revenue['produced_value'] = $quantity === null ? null : bcmul((string) $quantity, $net, 3);
                    $revenue['delivered_value'] = $lots->isEmpty() ? null : bcmul((string) $delivered, $net, 3);
                }

                $sum = fn (Collection $rows) => $rows->where('currency_code', $currency)
                    ->reduce(fn (string $total, $invoice) => $invoice->kind === 'credit_note' ? bcsub($total, (string) $invoice->amount, 3) : bcadd($total, (string) $invoice->amount, 3), '0');
                $all = $invoices[$customerOrder->id] ?? collect();
                if ($all->contains(fn ($invoice) => $invoice->currency_code !== $currency)) {
                    $warnings[] = 'invoice_currency';
                }
                $own = $all->where('production_order_id', $id);
                $shared = $all->whereNull('production_order_id');
                if ($own->isNotEmpty() || ($shared->isNotEmpty() && (int) ($siblings[$customerOrder->id] ?? 0) <= 1)) {
                    // Named on the invoice, or the only OF of the order: the invoices are this OF's.
                    $revenue['invoiced'] = $sum((int) ($siblings[$customerOrder->id] ?? 0) <= 1 ? $own->merge($shared) : $own);
                    $revenue['invoiced_scope'] = 'of';
                    $revenue['uninvoiced'] = $revenue['produced_value'] === null ? null : bcsub($revenue['produced_value'], $revenue['invoiced'], 3);
                } elseif ($shared->isNotEmpty()) {
                    // Invoices of an order with several OFs: they stay with the order, no share is invented.
                    $revenue['invoiced'] = $sum($shared);
                    $revenue['invoiced_scope'] = 'order';
                }
            }

            // --- Gain: only when what it compares is complete.
            $missing = array_values(array_filter([
                $revenueMissing, $revenueMissing === null && $quantity === null ? 'no_production' : null,
                $unknownLines > 0 ? 'unknown_material_cost' : null, $hasPlan && ! $hasConsumption ? 'no_consumption' : null, $excluded > 0 ? 'expense_currency' : null,
            ]));
            $gain = ['revenue' => null, 'cost' => null, 'amount' => null, 'margin_pct' => null, 'estimated' => bccomp($estimated, '0', 3) > 0, 'missing' => $missing];
            if ($missing === [] && $revenue['produced_value'] !== null) {
                $gain['revenue'] = $revenue['produced_value'];
                $gain['cost'] = $totalCost;
                $gain['amount'] = bcsub($revenue['produced_value'], $totalCost, 3);
                $gain['margin_pct'] = bccomp($revenue['produced_value'], '0', 3) > 0 ? round((float) $gain['amount'] / (float) $revenue['produced_value'] * 100, 1) : null;
            }

            $result[$id] = [
                'currency' => $currency,
                'materials' => $materialRows,
                'material_cost' => ['known' => $known, 'estimated' => $estimated, 'total' => $materialTotal, 'complete' => $materialComplete, 'unknown_lines' => $unknownLines],
                'planned_material_cost' => $hasPlan ? $plannedCost : null,
                'expenses' => ['total' => $expenseTotal, 'by_category' => $byCategory, 'excluded' => $excluded],
                'total_cost' => $totalCost, 'cost_complete' => $costComplete,
                'produced' => ['quantity' => $quantity, 'delivered' => $delivered, 'source' => $lots->isNotEmpty() ? 'lots' : ($quantity === null ? null : 'order')],
                // What one piece cost, when every cost is known and something was made.
                'unit_cost' => $costComplete && $quantity !== null && $quantity > 0 ? bcdiv($totalCost, (string) $quantity, 4) : null,
                'revenue' => $revenue,
                'gain' => $gain,
                'warnings' => array_values(array_unique($warnings)),
            ];
        }

        return $result;
    }

    /**
     * What every order together took from each received lot, to set against what the lot held.
     *
     * @param  list<int>  $batchIds
     * @return array<int, string> lot id => quantity consumed
     */
    public function consumedPerLot(array $batchIds): array
    {
        return DB::table('production_order_consumptions')->where('company_id', $this->context->companyId())->whereIn('raw_material_batch_id', $batchIds)
            ->groupBy('raw_material_batch_id')->pluck(DB::raw('SUM(quantity)'), 'raw_material_batch_id')->map(fn ($quantity) => (string) $quantity)->all();
    }
}
