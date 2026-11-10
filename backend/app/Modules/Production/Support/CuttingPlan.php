<?php

namespace App\Modules\Production\Support;

use App\Modules\Common\Support\ReferenceCodes;
use App\Modules\Production\Models\CuttingSpread;
use App\Modules\Production\Models\ProductionOrder;
use Illuminate\Support\Facades\DB;

/**
 * The cutting of an OF, spread by spread (matelas): what each placement makes and what it is expected to leave.
 *
 * For a spread of L layers of a placement of length P (plus the allowance at the ends of each layer, if any):
 *
 *     pieces of a size  = L × pieces of that size in one layer
 *     fabric (m)        = L × (P + allowance)
 *     fabric (kg)       = fabric (m) × laize (cm) / 100 × grammage (g/m²) / 1000
 *     cutting waste     = fabric (kg) × (100 − efficiency of the placement) / 100
 *
 * Planned figures come from the layers planned, done figures from the layers really cut. The waste of a spread mixes
 * all its sizes; it is shared between them by their pieces — an estimate, said as such, never a weight.
 *
 * The garments are counted on the MAIN fabric (the most metres planned): an interlining or a lining is cut for the
 * same garments, its pieces are not more garments. Every fabric adds its metres and its waste.
 */
final class CuttingPlan
{
    public function __construct(private readonly CuttingWaste $cutting, private readonly ReferenceCodes $codes) {}

    /**
     * @return array<string, mixed>|null null when the OF has no spread
     */
    public function forOrder(ProductionOrder $of): ?array
    {
        $spreads = CuttingSpread::query()->where('production_order_id', $of->id)->orderBy('id')->get();
        if ($spreads->isEmpty()) {
            return null;
        }
        $materials = DB::table('raw_materials')->whereIn('id', $spreads->pluck('raw_material_id')->unique())
            ->get(['id', 'ulid', 'reference', 'name', 'unit_id', 'width_cm', 'grammage_gsm', 'waste_type_id'])->keyBy('id');
        $batches = DB::table('raw_material_batches')->whereIn('id', $spreads->pluck('raw_material_batch_id')->filter()->unique())->get(['id', 'ulid', 'batch_number'])->keyBy('id');

        $rows = $spreads->map(function (CuttingSpread $spread) use ($materials, $batches) {
            $material = $materials[$spread->getAttribute('raw_material_id')];
            $wasteType = $material->waste_type_id ?? $this->cutting->scrapsType();

            return [
                'ulid' => $spread->ulid,
                'material' => ['ulid' => $material->ulid, 'reference' => $material->reference, 'name' => $material->name, 'unit' => $this->codes->code('units', (int) $material->unit_id),
                    'width_cm' => $material->width_cm === null ? null : (string) $material->width_cm, 'grammage_gsm' => $material->grammage_gsm === null ? null : (string) $material->grammage_gsm],
                'batch' => ($batch = $batches[$spread->getAttribute('raw_material_batch_id')] ?? null) === null ? null : ['ulid' => $batch->ulid, 'batch_number' => $batch->batch_number],
                'marker_length_m' => (string) $spread->getAttribute('marker_length_m'), 'efficiency_pct' => (string) $spread->getAttribute('efficiency_pct'),
                'end_allowance_cm' => $spread->getAttribute('end_allowance_cm') === null ? null : (string) $spread->getAttribute('end_allowance_cm'),
                'ratio' => $spread->ratio, 'pieces_per_layer' => $this->perLayer($spread),
                'layers_planned' => (int) $spread->getAttribute('layers_planned'),
                'layers_done' => $spread->getAttribute('layers_done') === null ? null : (int) $spread->getAttribute('layers_done'),
                'cut_on' => $spread->getAttribute('cut_on')?->toDateString(), 'notes' => $spread->getAttribute('notes'),
                'consumption_recorded' => $spread->getAttribute('consumption_recorded_at') !== null,
                'waste_type' => $this->cutting->type($wasteType),
                'planned' => $this->figures($spread, $material, (int) $spread->getAttribute('layers_planned')),
                'done' => $spread->getAttribute('layers_done') === null ? null : $this->figures($spread, $material, (int) $spread->getAttribute('layers_done')),
                // Kept for the totals below, not shown.
                '_type' => $wasteType ?? 0, '_material' => (int) $material->id,
            ];
        });

        $anyDone = $rows->contains(fn (array $row) => $row['done'] !== null);
        $sum = fn (string $stage, string $key) => $rows->reduce(function (?string $total, array $row) use ($stage, $key) {
            $value = $row[$stage][$key] ?? null;

            return $value === null ? $total : bcadd($total ?? '0', (string) $value, 6);
        });
        $round = fn (?string $value, int $scale) => $value === null ? null : CuttingWaste::round($value, $scale);
        $main = $rows->groupBy('_material')->map(fn ($group) => $group->reduce(fn (string $t, array $row) => bcadd($t, $row['planned']['metres'], 6), '0'))
            ->sortDesc(SORT_NUMERIC)->keys()->first();
        $garments = $rows->filter(fn (array $row) => $row['_material'] === (int) $main);

        return [
            'main_material' => $garments->first()['material'],
            'spreads' => $rows->map(fn (array $row) => array_diff_key($row, ['_type' => 0, '_material' => 0]))->values()->all(),
            'totals' => [
                'planned' => ['pieces' => (int) $garments->sum(fn (array $row) => $row['planned']['pieces']), 'metres' => $round($sum('planned', 'metres'), 3),
                    'fabric_kg' => $round($sum('planned', 'fabric_kg'), 3), 'waste_kg' => $round($sum('planned', 'waste_kg'), 3)],
                'done' => ! $anyDone ? null : ['pieces' => (int) $garments->sum(fn (array $row) => $row['done']['pieces'] ?? 0), 'metres' => $round($sum('done', 'metres'), 3),
                    'fabric_kg' => $round($sum('done', 'fabric_kg'), 3), 'waste_kg' => $round($sum('done', 'waste_kg'), 3)],
                // Spreads still waiting for their layers count in "done" by nothing: they are not cut yet.
                'spreads_to_cut' => $rows->filter(fn (array $row) => $row['done'] === null)->count(),
            ],
            'by_size' => $this->bySize($of, $rows->all(), (int) $main),
            'by_type' => $rows->groupBy('_type')->map(fn ($group, $type) => [
                'type_id' => (int) $type,
                'planned_kg' => $round($group->reduce(fn (?string $t, array $row) => $row['planned']['waste_kg'] === null ? $t : bcadd($t ?? '0', $row['planned']['waste_kg'], 6)), 6),
                'done_kg' => ! $anyDone ? null : $round($group->reduce(fn (?string $t, array $row) => ($row['done']['waste_kg'] ?? null) === null ? $t : bcadd($t ?? '0', $row['done']['waste_kg'], 6)), 6),
            ])->values()->all(),
            // What was cut of each fabric beside what the OF says it consumed of it: two figures that should meet.
            'fabrics' => $rows->groupBy('_material')->map(function ($group, $materialId) use ($of, $round) {
                $consumed = DB::table('production_order_consumptions')->where('production_order_id', $of->id)->where('raw_material_id', $materialId)->sum('quantity');
                $cut = $group->reduce(fn (?string $t, array $row) => $row['done'] === null ? $t : bcadd($t ?? '0', $row['done']['metres'], 6));

                return ['material' => $group->first()['material'], 'planned_m' => $round($group->reduce(fn (string $t, array $row) => bcadd($t, $row['planned']['metres'], 6), '0'), 3),
                    'cut_m' => $round($cut, 3), 'consumed_m' => bcadd((string) $consumed, '0', 3)];
            })->values()->all(),
        ];
    }

    /**
     * The cutting waste of the layers really cut, by OF: only OFs with at least one spread cut are present.
     *
     * @param  list<int>  $orderIds
     * @return array<int, string>
     */
    public function cutWasteByOrder(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }
        $spreads = CuttingSpread::query()->whereIn('production_order_id', $orderIds)->whereNotNull('layers_done')->get();
        $materials = DB::table('raw_materials')->whereIn('id', $spreads->pluck('raw_material_id')->unique())->get(['id', 'width_cm', 'grammage_gsm'])->keyBy('id');
        $out = [];
        foreach ($spreads as $spread) {
            $waste = $this->figures($spread, $materials[$spread->getAttribute('raw_material_id')], (int) $spread->getAttribute('layers_done'))['waste_kg'];
            if ($waste !== null) {
                $out[(int) $spread->getAttribute('production_order_id')] = bcadd($out[(int) $spread->getAttribute('production_order_id')] ?? '0', $waste, 6);
            }
        }

        return $out;
    }

    public function perLayer(CuttingSpread $spread): int
    {
        return (int) array_sum(array_map(fn (array $line) => (int) $line['pieces'], $spread->ratio ?? []));
    }

    /** The metres a number of layers takes, the allowance at the ends included. */
    public function metres(CuttingSpread $spread, int $layers): string
    {
        $length = bcadd((string) $spread->getAttribute('marker_length_m'), bcdiv((string) ($spread->getAttribute('end_allowance_cm') ?? '0'), '100', 4), 4);

        return bcmul($length, (string) $layers, 6);
    }

    /**
     * @return array{pieces: int, by_size: array<string, int>, metres: string, fabric_kg: string|null, waste_kg: string|null}
     */
    private function figures(CuttingSpread $spread, object $material, int $layers): array
    {
        $metres = $this->metres($spread, $layers);
        $fabric = $material->width_cm === null || $material->grammage_gsm === null ? null
            : bcdiv(bcmul(bcmul($metres, (string) $material->width_cm, 8), (string) $material->grammage_gsm, 8), '100000', 6);
        $bySize = [];
        foreach ($spread->ratio ?? [] as $line) {
            $bySize[$line['size']] = (int) $line['pieces'] * $layers;
        }

        return ['pieces' => $this->perLayer($spread) * $layers, 'by_size' => $bySize, 'metres' => CuttingWaste::round($metres, 3),
            'fabric_kg' => $fabric === null ? null : CuttingWaste::round($fabric, 3),
            'waste_kg' => $fabric === null ? null : CuttingWaste::round(bcdiv(bcmul($fabric, bcsub('100', (string) $spread->getAttribute('efficiency_pct'), 2), 8), '100', 6), 3)];
    }

    /**
     * Each size: the pieces the OF orders, those the spreads of the main fabric plan and cut, and its share of the
     * cutting waste of every spread (each spread's waste — done, else planned — shared by the pieces of each size it makes).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function bySize(ProductionOrder $of, array $rows, int $main): array
    {
        $ordered = [];
        foreach ($of->size_quantities ?? [] as $line) {
            $ordered[$line['size']] = (int) $line['quantity'];
        }
        $sizes = array_keys($ordered);
        foreach ($rows as $row) {
            foreach ($row['ratio'] as $line) {
                if (! in_array($line['size'], $sizes, true)) {
                    $sizes[] = $line['size'];
                }
            }
        }

        return array_map(function (string $size) use ($ordered, $rows, $main) {
            $planned = 0;
            $cut = null;
            $waste = null;
            foreach ($rows as $row) {
                if ($row['_material'] === $main) {
                    $planned += $row['planned']['by_size'][$size] ?? 0;
                    if ($row['done'] !== null) {
                        $cut = ($cut ?? 0) + ($row['done']['by_size'][$size] ?? 0);
                    }
                }
                $basis = $row['done'] ?? $row['planned'];
                if ($basis['waste_kg'] !== null && $basis['pieces'] > 0 && ($basis['by_size'][$size] ?? 0) > 0) {
                    $waste = bcadd($waste ?? '0', bcdiv(bcmul($basis['waste_kg'], (string) $basis['by_size'][$size], 8), (string) $basis['pieces'], 8), 8);
                }
            }

            return ['size' => $size, 'ordered' => $ordered[$size] ?? null, 'planned' => $planned, 'cut' => $cut, 'waste_kg' => $waste === null ? null : CuttingWaste::round($waste, 3)];
        }, $sizes);
    }
}
