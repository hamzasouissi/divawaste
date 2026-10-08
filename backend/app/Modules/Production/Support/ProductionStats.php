<?php

namespace App\Modules\Production\Support;

use App\Modules\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Where the waste of a period comes from in production: by manufacturing order, client, article, stage, raw material
 * and supplier, with the waste per piece, the share of the consumed material that became waste, and its money.
 *
 * Two kinds of figures are kept apart everywhere. MEASURED: the lot names its manufacturing order. ESTIMATED: the lot
 * only says the stage it was produced at; its weight is then shared between the orders that were at that stage on that
 * site on that day, in proportion to the pieces they made there. A lot that says nothing — or whose stage no order was
 * at — is reported as not attributed, never guessed further.
 */
final class ProductionStats
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * @return array<string, mixed>
     */
    public function compute(string $from, string $to, ?int $siteId = null): array
    {
        $companyId = $this->context->companyId();
        $end = CarbonImmutable::parse($to)->addDay()->startOfDay();

        $orders = DB::table('production_orders as o')->join('articles as a', 'a.id', '=', 'o.article_id')
            ->leftJoin('customer_orders as c', 'c.id', '=', 'o.customer_order_id')
            ->leftJoin('production_clients as cl', fn ($join) => $join->on('cl.id', '=', DB::raw('COALESCE(c.production_client_id, a.production_client_id)')))
            ->where('o.company_id', $companyId)
            ->get(['o.id', 'o.ulid', 'o.order_number', 'o.status', 'o.quantity_planned', 'o.quantity_produced', 'o.started_on', 'o.finished_on',
                'a.id as article_id', 'a.reference', 'a.name as article_name', 'c.order_number as customer_order', 'cl.id as client_id', 'cl.name as client'])->keyBy('id');
        $stages = DB::table('production_order_steps')->where('company_id', $companyId)->get(['production_order_id', 'production_step_id', 'site_id', 'pieces_done', 'started_on', 'finished_on']);
        $materials = DB::table('raw_materials as m')->leftJoin('production_suppliers as s', 's.id', '=', 'm.production_supplier_id')->leftJoin('units as u', 'u.id', '=', 'm.unit_id')
            ->where('m.company_id', $companyId)->get(['m.id', 'm.ulid', 'm.reference', 'm.name', 'm.grammage_gsm', 'm.width_cm', 'u.code as unit', 's.id as supplier_id', 's.name as supplier'])->keyBy('id');
        $steps = DB::table('production_steps')->get(['id', 'code', 'name'])->keyBy('id');

        // What each order consumed, in kilograms when it can be known (kg, or metres of a fabric whose width and weight are given).
        $consumedKg = [];
        $singleMaterial = [];
        foreach (DB::table('production_order_consumptions')->where('company_id', $companyId)->get(['production_order_id', 'raw_material_id', 'quantity'])->groupBy('production_order_id') as $orderId => $lines) {
            $kg = '0';
            foreach ($lines as $line) {
                $one = $this->toKg((string) $line->quantity, $materials[$line->raw_material_id] ?? null);
                $kg = $one === null || $kg === null ? null : bcadd($kg, $one, 3);
            }
            $consumedKg[$orderId] = $kg;
            $distinct = $lines->pluck('raw_material_id')->unique();
            $singleMaterial[$orderId] = $distinct->count() === 1 ? (int) $distinct->first() : null;
        }

        $lots = DB::table('waste_lots as l')->where('l.company_id', $companyId)->whereNull('l.deleted_at')
            // A split or a grouping moves weight between lots: only what was generated is counted, as in the reports.
            ->where('l.origin_type', 'created')->where('l.generated_at', '>=', $from)->where('l.generated_at', '<', $end)
            ->when($siteId !== null, fn ($query) => $query->where('l.site_id', $siteId))
            ->get(['l.id', 'l.site_id', 'l.production_order_id', 'l.production_step_id', 'l.raw_material_id', 'l.net_weight_kg', 'l.generated_at']);
        $amounts = DB::table('pickup_lots')->whereIn('waste_lot_id', $lots->pluck('id'))->whereNotNull('net_amount')
            ->groupBy('waste_lot_id')->pluck(DB::raw('SUM(net_amount)'), 'waste_lot_id');

        // Each lot becomes one share (measured) or several (estimated): [order, stage, material, kg, measured, lots, amount].
        $shares = [];
        $unattributed = ['kg' => '0', 'lots' => 0];
        foreach ($lots as $lot) {
            $kg = (string) $lot->net_weight_kg;
            $amount = (string) ($amounts[$lot->id] ?? '0');
            if ($lot->production_order_id !== null) {
                $shares[] = [(int) $lot->production_order_id, $lot->production_step_id, $lot->raw_material_id, $kg, true, 1, $amount];

                continue;
            }
            $candidates = $lot->production_step_id === null ? [] : $this->ordersAtStage($stages, $orders, (int) $lot->production_step_id, (int) $lot->site_id, substr((string) $lot->generated_at, 0, 10));
            $weight = array_sum($candidates);
            if ($weight <= 0) {
                $unattributed = ['kg' => bcadd($unattributed['kg'], $kg, 3), 'lots' => $unattributed['lots'] + 1];
                // Still known by its stage and its material, when it says them.
                $shares[] = [null, $lot->production_step_id, $lot->raw_material_id, $kg, false, 1, $amount];

                continue;
            }
            foreach ($candidates as $orderId => $pieces) {
                $part = $pieces / $weight;
                $shares[] = [$orderId, $lot->production_step_id, $lot->raw_material_id ?? ($singleMaterial[$orderId] ?? null),
                    bcmul($kg, number_format($part, 6, '.', ''), 3), false, $part, bcmul($amount, number_format($part, 6, '.', ''), 3)];
            }
        }

        $group = function (callable $key, callable $describe) use ($shares, $orders, $consumedKg) {
            $rows = [];
            foreach ($shares as [$orderId, $stepId, $materialId, $kg, $measured, $count, $amount]) {
                $k = $key($orderId === null ? null : ($orders[$orderId] ?? null), $stepId, $materialId);
                if ($k === null) {
                    continue;
                }
                $rows[$k] ??= ['measured_kg' => '0', 'estimated_kg' => '0', 'lots' => 0.0, 'net_amount' => '0', 'orders' => []];
                $rows[$k][$measured ? 'measured_kg' : 'estimated_kg'] = bcadd($rows[$k][$measured ? 'measured_kg' : 'estimated_kg'], $kg, 3);
                $rows[$k]['lots'] += $count;
                $rows[$k]['net_amount'] = bcadd($rows[$k]['net_amount'], $amount, 3);
                if ($orderId !== null) {
                    $rows[$k]['orders'][$orderId] = true;
                }
            }

            return collect($rows)->map(function (array $row, $k) use ($describe, $orders, $consumedKg) {
                $total = bcadd($row['measured_kg'], $row['estimated_kg'], 3);
                // Pieces and consumption of the orders behind the row: the ones that produced this waste.
                $pieces = 0;
                $consumed = '0';
                foreach (array_keys($row['orders']) as $orderId) {
                    $pieces += (int) ($orders[$orderId]->quantity_produced ?? 0);
                    $consumed = $consumed === null || ! isset($consumedKg[$orderId]) ? null : bcadd($consumed, $consumedKg[$orderId], 3);
                }
                $consumed = $row['orders'] === [] ? null : $consumed;

                return $describe($k) + [
                    'lots' => (int) round($row['lots']), 'measured_kg' => $row['measured_kg'], 'estimated_kg' => $row['estimated_kg'], 'total_kg' => $total,
                    'pieces' => $pieces > 0 ? $pieces : null,
                    'g_per_piece' => $pieces > 0 ? round((float) $total * 1000 / $pieces, 1) : null,
                    'consumed_kg' => $consumed !== null && bccomp($consumed, '0', 3) > 0 ? $consumed : null,
                    // The share of the consumed material that ended as waste.
                    'waste_rate_pct' => $consumed !== null && bccomp($consumed, '0', 3) > 0 ? round((float) $total / (float) $consumed * 100, 1) : null,
                    'net_amount' => $row['net_amount'],
                ];
            })->sortByDesc(fn (array $row) => (float) $row['total_kg'])->values()->all();
        };
        $stepName = fn ($id) => json_decode((string) ($steps[$id]->name ?? '{}'), true);

        $byOrder = $group(fn ($order) => $order?->id, fn ($id) => ['key' => $orders[$id]->ulid, 'label' => $orders[$id]->order_number,
            'sublabel' => trim($orders[$id]->reference.' · '.($orders[$id]->client ?? ''), ' ·'), 'status' => $orders[$id]->status]);
        $measured = array_reduce($shares, fn (string $sum, array $s) => $s[4] ? bcadd($sum, $s[3], 3) : $sum, '0');
        $estimated = array_reduce($shares, fn (string $sum, array $s) => ! $s[4] && $s[0] !== null ? bcadd($sum, $s[3], 3) : $sum, '0');
        $total = bcadd(bcadd($measured, $estimated, 3), $unattributed['kg'], 3);
        $pieces = array_sum(array_map(fn (array $row) => (int) ($row['pieces'] ?? 0), $byOrder));
        $attributed = bcadd($measured, $estimated, 3);

        return [
            'period' => ['from' => $from, 'to' => $to],
            'totals' => [
                'waste_kg' => $total, 'measured_kg' => $measured, 'estimated_kg' => $estimated, 'unattributed_kg' => $unattributed['kg'], 'unattributed_lots' => $unattributed['lots'],
                'lots' => $lots->count(), 'orders' => count($byOrder), 'pieces' => $pieces > 0 ? $pieces : null,
                'g_per_piece' => $pieces > 0 ? round((float) $attributed * 1000 / $pieces, 1) : null,
            ],
            'by_order' => $byOrder,
            'by_client' => $group(fn ($order) => $order?->client_id, fn ($id) => ['key' => (string) $id, 'label' => (string) $orders->firstWhere('client_id', $id)?->client, 'sublabel' => null]),
            'by_article' => $group(fn ($order) => $order?->article_id, fn ($id) => ['key' => (string) $id, 'label' => (string) $orders->firstWhere('article_id', $id)?->reference,
                'sublabel' => $orders->firstWhere('article_id', $id)?->article_name]),
            'by_step' => $group(fn ($order, $stepId) => $stepId, fn ($id) => ['key' => $steps[$id]->code ?? (string) $id, 'label' => $stepName($id), 'sublabel' => null]),
            'by_material' => $group(fn ($order, $stepId, $materialId) => $materialId, fn ($id) => ['key' => $materials[$id]->ulid, 'label' => $materials[$id]->reference,
                'sublabel' => trim($materials[$id]->name.' · '.($materials[$id]->supplier ?? ''), ' ·')]),
            'by_supplier' => $group(fn ($order, $stepId, $materialId) => $materialId === null ? null : ($materials[$materialId]->supplier_id ?? null),
                fn ($id) => ['key' => (string) $id, 'label' => (string) $materials->firstWhere('supplier_id', $id)?->supplier, 'sublabel' => null]),
        ];
    }

    /**
     * The orders that were at a stage on a site on a day, each with the pieces it made there (its weight in the sharing).
     *
     * @param  Collection<int, \stdClass>  $stages
     * @param  Collection<int|string, \stdClass>  $orders
     * @return array<int, int> order id => pieces
     */
    private function ordersAtStage($stages, $orders, int $stepId, int $siteId, string $day): array
    {
        $found = [];
        foreach ($stages as $stage) {
            $order = $orders[$stage->production_order_id] ?? null;
            if ($order === null || (int) $stage->production_step_id !== $stepId || (int) $stage->site_id !== $siteId || $order->status === 'cancelled') {
                continue;
            }
            // The dates of the stage when they are known, otherwise those of the order; an order not started is nowhere.
            $start = $stage->started_on ?? $order->started_on;
            $finish = $stage->finished_on ?? $order->finished_on;
            if ($start === null || $start > $day || ($finish !== null && $finish < $day)) {
                continue;
            }
            $found[(int) $order->id] = max(1, (int) ($stage->pieces_done ?? $order->quantity_planned));
        }

        return $found;
    }

    private function toKg(string $quantity, ?\stdClass $material): ?string
    {
        return match (true) {
            $material === null => null,
            $material->unit === 'kg' => $quantity,
            // Metres of fabric × width × weight per square metre.
            $material->unit === 'm' && $material->width_cm !== null && $material->grammage_gsm !== null => bcdiv(bcmul(bcmul($quantity, (string) $material->width_cm, 6), (string) $material->grammage_gsm, 6), '100000', 3),
            default => null,
        };
    }
}
