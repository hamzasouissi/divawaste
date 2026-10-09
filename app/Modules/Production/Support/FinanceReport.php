<?php

namespace App\Modules\Production\Support;

use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Costs and gains of the manufacturing orders of a period, with what is missing to trust them. An order belongs to the
 * period by the day it finished, or started when it is not finished, or was created when it has no date. Cancelled
 * orders are left out. Every figure comes from {@see ProductionFinance}: this class only adds them up, and never adds
 * a gain that could not be calculated.
 */
final class FinanceReport
{
    public function __construct(private readonly TenantContext $context, private readonly ProductionFinance $finance) {}

    /**
     * @return array<string, mixed>
     */
    public function compute(string $from, string $to): array
    {
        $orders = DB::table('production_orders as o')->join('articles as a', 'a.id', '=', 'o.article_id')
            ->leftJoin('customer_orders as c', 'c.id', '=', 'o.customer_order_id')
            ->leftJoin('production_clients as cl', fn ($join) => $join->on('cl.id', '=', DB::raw('COALESCE(c.production_client_id, a.production_client_id)')))
            ->where('o.company_id', $this->context->companyId())->where('o.status', '!=', 'cancelled')
            ->whereRaw('COALESCE(o.finished_on, o.started_on, DATE(o.created_at)) BETWEEN ? AND ?', [$from, $to])
            ->orderBy('o.order_number')
            ->get(['o.id', 'o.ulid', 'o.order_number', 'o.status', 'o.article_id', 'o.customer_order_id', 'o.quantity_planned', 'o.quantity_produced',
                'a.reference as article', 'c.id as order_id', 'c.order_number as customer_order', 'cl.id as client_id', 'cl.name as client']);
        $figures = $this->finance->forOrders($orders);

        $add = fn (?string $sum, ?string $value) => $value === null ? $sum : bcadd($sum ?? '0', $value, 3);
        $rows = [];
        $totals = ['orders' => $orders->count(), 'material_cost_known' => '0', 'material_cost_estimated' => '0', 'expenses' => '0', 'total_cost' => '0',
            'planned_material_cost' => null, 'produced_value' => null, 'invoiced' => null, 'gain' => null, 'gain_orders' => 0, 'incomplete_orders' => 0];
        $clients = [];
        $materials = [];
        $suppliers = [];
        $missing = ['unknown_material_cost' => 0, 'no_consumption' => 0, 'no_price' => 0, 'no_order' => 0, 'no_production' => 0, 'expense_currency' => 0, 'order_currency' => 0];
        // An invoice of an order with several OFs is counted once, with the order.
        $orderInvoices = [];

        foreach ($orders as $order) {
            $f = $figures[$order->id];
            $rows[] = [
                'ulid' => $order->ulid, 'order_number' => $order->order_number, 'status' => $order->status, 'article' => $order->article, 'client' => $order->client,
                'customer_order' => $order->customer_order, 'quantity_planned' => (int) $order->quantity_planned, 'quantity_produced' => $f['produced']['quantity'],
                'planned_material_cost' => $f['planned_material_cost'], 'material_cost_known' => $f['material_cost']['known'], 'material_cost_estimated' => $f['material_cost']['estimated'],
                'expenses' => $f['expenses']['total'], 'total_cost' => $f['total_cost'], 'cost_complete' => $f['cost_complete'], 'unit_cost' => $f['unit_cost'],
                'unit_price' => $f['revenue']['net_unit_price'], 'produced_value' => $f['revenue']['produced_value'],
                'invoiced' => $f['revenue']['invoiced_scope'] === 'of' ? $f['revenue']['invoiced'] : null,
                'gain' => $f['gain']['amount'], 'margin_pct' => $f['gain']['margin_pct'], 'estimated' => $f['gain']['estimated'], 'missing' => $f['gain']['missing'],
            ];
            $totals['material_cost_known'] = bcadd($totals['material_cost_known'], $f['material_cost']['known'], 3);
            $totals['material_cost_estimated'] = bcadd($totals['material_cost_estimated'], $f['material_cost']['estimated'], 3);
            $totals['expenses'] = bcadd($totals['expenses'], $f['expenses']['total'], 3);
            $totals['total_cost'] = bcadd($totals['total_cost'], $f['total_cost'], 3);
            $totals['planned_material_cost'] = $add($totals['planned_material_cost'], $f['planned_material_cost']);
            $totals['produced_value'] = $add($totals['produced_value'], $f['revenue']['produced_value']);
            if ($f['revenue']['invoiced_scope'] === 'of') {
                $totals['invoiced'] = $add($totals['invoiced'], $f['revenue']['invoiced']);
            } elseif ($f['revenue']['invoiced_scope'] === 'order') {
                $orderInvoices[$order->order_id] = $f['revenue']['invoiced'];
            }
            if ($f['gain']['amount'] !== null) {
                $totals['gain'] = $add($totals['gain'], $f['gain']['amount']);
                $totals['gain_orders']++;
            } else {
                $totals['incomplete_orders']++;
            }
            foreach ($f['gain']['missing'] as $reason) {
                $missing[$reason] = ($missing[$reason] ?? 0) + 1;
            }

            $key = $order->client_id ?? 0;
            $clients[$key] ??= ['client' => $order->client, 'orders' => 0, 'quantity_produced' => 0, 'total_cost' => '0', 'produced_value' => null, 'gain' => null, 'gain_orders' => 0];
            $clients[$key]['orders']++;
            $clients[$key]['quantity_produced'] += (int) ($f['produced']['quantity'] ?? 0);
            $clients[$key]['total_cost'] = bcadd($clients[$key]['total_cost'], $f['total_cost'], 3);
            $clients[$key]['produced_value'] = $add($clients[$key]['produced_value'], $f['revenue']['produced_value']);
            if ($f['gain']['amount'] !== null) {
                $clients[$key]['gain'] = $add($clients[$key]['gain'], $f['gain']['amount']);
                $clients[$key]['gain_orders']++;
            }

            foreach ($f['materials'] as $material) {
                $m = $materials[$material['material']] ?? ['material' => $material['material'], 'reference' => $material['reference'], 'name' => $material['name'], 'unit' => $material['unit'],
                    'planned' => null, 'consumed' => '0.000', 'allocated' => '0.000', 'unallocated' => '0.000', 'cost_known' => '0.000', 'cost_estimated' => '0.000', 'unknown_quantity' => '0.000'];
                // Planned is only comparable where something was consumed: an order not started yet would look like a saving.
                $m['planned'] = $material['lines'] === [] ? $m['planned'] : $add($m['planned'], $material['planned']);
                foreach (['consumed', 'allocated', 'unallocated', 'cost_known', 'cost_estimated', 'unknown_quantity'] as $column) {
                    $m[$column] = bcadd($m[$column], $material[$column], 3);
                }
                $materials[$material['material']] = $m;
                foreach ($material['lines'] as $line) {
                    $name = $line['supplier']['name'] ?? null;
                    $s = $suppliers[$name ?? ''] ?? ['supplier' => $name, 'lines' => 0, 'lots' => [], 'cost' => '0.000', 'unpriced_lines' => 0];
                    $s['lines']++;
                    if ($line['batch'] !== null) {
                        $s['lots'][$line['batch']] = true;
                    }
                    $s['cost'] = bcadd($s['cost'], $line['basis'] === 'lot' ? (string) $line['cost'] : '0', 3);
                    $s['unpriced_lines'] += $line['basis'] === 'unknown' ? 1 : 0;
                    $suppliers[$name ?? ''] = $s;
                }
            }
        }
        foreach ($orderInvoices as $amount) {
            $totals['invoiced'] = $add($totals['invoiced'], $amount);
        }

        return [
            'period' => ['from' => $from, 'to' => $to], 'currency' => $this->finance->currency(),
            'totals' => $totals,
            'orders' => $rows,
            'by_client' => array_values($clients),
            'by_material' => array_values(array_map(function (array $m) {
                $variance = $m['planned'] === null ? null : bcsub($m['consumed'], $m['planned'], 3);

                return $m + ['variance' => $variance, 'variance_pct' => $variance === null || bccomp($m['planned'], '0', 3) <= 0 ? null : round((float) $variance / (float) $m['planned'] * 100, 1)];
            }, $materials)),
            // Consumption with a known lot goes to the supplier of that lot; the rest has no known supplier.
            'by_supplier' => array_values(array_map(fn (array $s) => ['lots' => count($s['lots'])] + $s, $suppliers)),
            'missing' => array_filter($missing),
        ];
    }
}
