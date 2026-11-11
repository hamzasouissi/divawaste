<?php

namespace App\Modules\Production\Support;

use App\Modules\Common\Support\ReferenceCodes;
use App\Modules\Production\Models\CuttingSpread;
use App\Modules\Production\Models\ProductionOrder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The cutting of an OF, fabric by fabric, spread by spread (matelas). For a spread of L layers (couches) of length P:
 *
 *     fabric (m)     = L × P
 *     fabric (kg)    = fabric (m) × laize (cm) / 100 × grammage (g/m²) / 1000
 *     cutting waste  = fabric (kg) × (100 − efficiency of the placement) / 100
 *
 * The laize and the grammage are the spread's own when the rolls laid differ, else the raw material's. Planned
 * figures come from the layers planned, done figures from the layers really cut. The sizes are not here: the waste
 * depends on the fabric laid, they stay on the OF.
 *
 * Every fabric of the article is listed, with or without spreads, with the efficiency a new spread starts from: the
 * article's for that fabric, else the company's.
 */
final class CuttingPlan
{
    public function __construct(private readonly CuttingWaste $cutting, private readonly ReferenceCodes $codes) {}

    /**
     * @return array<string, mixed>
     */
    public function forOrder(ProductionOrder $of): array
    {
        $spreads = CuttingSpread::query()->where('production_order_id', $of->id)->orderBy('id')->get();
        $default = $this->cutting->defaultEfficiency();
        $article = DB::table('article_materials')->where('article_id', $of->article_id)->orderBy('id')->get(['raw_material_id', 'quantity_per_piece', 'efficiency_pct']);
        $materials = DB::table('raw_materials')->whereIn('id', $article->pluck('raw_material_id')->merge($spreads->pluck('raw_material_id'))->unique())
            ->get(['id', 'ulid', 'reference', 'name', 'unit_id', 'width_cm', 'grammage_gsm'])->keyBy('id');
        $consumed = DB::table('production_order_consumptions')->where('production_order_id', $of->id)->groupBy('raw_material_id')
            ->selectRaw('raw_material_id, SUM(quantity) as quantity')->pluck('quantity', 'raw_material_id');
        $round = fn (?string $value) => $value === null ? null : CuttingWaste::round($value, 3);

        // The article's fabrics first, in its order, then any other fabric a spread was laid of.
        $ids = $article->filter(fn ($line) => $this->codes->code('units', (int) $materials[$line->raw_material_id]->unit_id) === 'm')->pluck('raw_material_id')
            ->merge($spreads->pluck('raw_material_id'))->map(fn ($id) => (int) $id)->unique()->values();

        $fabrics = $ids->map(function (int $id) use ($of, $spreads, $materials, $article, $consumed, $default, $round) {
            $material = $materials[$id];
            $line = $article->firstWhere('raw_material_id', $id);
            $waste = $this->cutting->applied($id, true);
            $rows = $spreads->filter(fn (CuttingSpread $spread) => (int) $spread->getAttribute('raw_material_id') === $id)
                ->map(fn (CuttingSpread $spread) => $this->row($spread, $material))->values();

            return [
                'material' => ['ulid' => $material->ulid, 'reference' => $material->reference, 'name' => $material->name,
                    'width_cm' => $material->width_cm === null ? null : (string) $material->width_cm, 'grammage_gsm' => $material->grammage_gsm === null ? null : (string) $material->grammage_gsm],
                'in_article' => $line !== null,
                // What the article says the OF needs of it: its consumption per piece for the pieces planned.
                'article_m' => $line === null ? null : $round(bcmul((string) $line->quantity_per_piece, (string) $of->quantity_planned, 6)),
                'efficiency_pct' => $line?->efficiency_pct !== null ? (string) $line->efficiency_pct : $default,
                'efficiency_from' => $line?->efficiency_pct !== null ? 'article' : 'company',
                // The waste it gives, each type with its share.
                'waste_types' => array_map(fn (array $line) => ['waste_type' => $this->cutting->type($line['type'] ?: null), 'share_pct' => $line['pct']], $waste['lines']),
                'spreads' => $rows->all(),
                'planned' => $this->sum($rows, 'planned'),
                'done' => $this->sum($rows, 'done'),
                'consumed_m' => isset($consumed[$id]) ? bcadd((string) $consumed[$id], '0', 3) : null,
                '_shares' => $waste['lines'],
            ];
        });
        $all = $fabrics->flatMap(fn (array $fabric) => $fabric['spreads']);

        return [
            'fabrics' => $fabrics->map(fn (array $fabric) => array_diff_key($fabric, ['_shares' => 0]))->all(),
            'totals' => [
                'spreads' => $all->count(),
                'spreads_to_cut' => $all->filter(fn (array $row) => $row['done'] === null)->count(),
                'planned' => $this->sum($all, 'planned'),
                'done' => $this->sum($all, 'done'),
            ],
            // Kept for the estimate of the OF, by type of waste: each fabric's waste shared by its types.
            'by_type' => $this->byType($fabrics),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $fabrics
     * @return list<array{type_id: int, planned_kg: string|null, done_kg: string|null}>
     */
    private function byType(Collection $fabrics): array
    {
        $out = [];
        foreach ($fabrics as $fabric) {
            foreach (['planned' => 'planned_kg', 'done' => 'done_kg'] as $stage => $key) {
                if (($fabric[$stage]['waste_kg'] ?? null) === null) {
                    continue;
                }
                foreach (CuttingWaste::split($fabric[$stage]['waste_kg'], $fabric['_shares']) as $type => $kg) {
                    $out[$type] ??= ['type_id' => $type, 'planned_kg' => null, 'done_kg' => null];
                    $out[$type][$key] = bcadd($out[$type][$key] ?? '0', $kg, 8);
                }
            }
        }

        return array_values(array_map(fn (array $row) => ['type_id' => $row['type_id'],
            'planned_kg' => $row['planned_kg'] === null ? null : CuttingWaste::round($row['planned_kg'], 6),
            'done_kg' => $row['done_kg'] === null ? null : CuttingWaste::round($row['done_kg'], 6)], $out));
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

    /**
     * @return array<string, mixed>
     */
    private function row(CuttingSpread $spread, object $material): array
    {
        $done = $spread->getAttribute('layers_done');
        $value = fn (string $key) => ($spread->getAttribute($key) ?? $material->{$key}) === null ? null : (string) ($spread->getAttribute($key) ?? $material->{$key});

        return [
            'ulid' => $spread->ulid,
            'length_m' => (string) $spread->getAttribute('length_m'),
            'efficiency_pct' => (string) $spread->getAttribute('efficiency_pct'),
            // The laize and grammage used: the spread's own (own_* true) or the raw material's.
            'width_cm' => $value('width_cm'), 'own_width' => $spread->getAttribute('width_cm') !== null,
            'grammage_gsm' => $value('grammage_gsm'), 'own_grammage' => $spread->getAttribute('grammage_gsm') !== null,
            'layers_planned' => (int) $spread->getAttribute('layers_planned'),
            'layers_done' => $done === null ? null : (int) $done,
            'cut_on' => $spread->getAttribute('cut_on')?->toDateString(), 'notes' => $spread->getAttribute('notes'),
            'planned' => $this->figures($spread, $material, (int) $spread->getAttribute('layers_planned')),
            'done' => $done === null ? null : $this->figures($spread, $material, (int) $done),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array{metres: string, fabric_kg: string|null, waste_kg: string|null, waste_pct: string|null}|null null when no row has that stage
     */
    private function sum(Collection $rows, string $stage): ?array
    {
        $rows = $rows->filter(fn (array $row) => $row[$stage] !== null);
        if ($rows->isEmpty()) {
            return null;
        }
        $add = function (string $key) use ($rows, $stage) {
            $values = $rows->pluck("{$stage}.{$key}")->filter(fn ($value) => $value !== null);

            return $values->isEmpty() ? null : $values->reduce(fn (string $total, string $value) => bcadd($total, $value, 3), '0');
        };
        $fabric = $add('fabric_kg');
        $waste = $add('waste_kg');

        return ['metres' => $add('metres') ?? '0.000', 'fabric_kg' => $fabric, 'waste_kg' => $waste,
            'waste_pct' => $fabric === null || $waste === null || bccomp($fabric, '0', 3) === 0 ? null : CuttingWaste::round(bcdiv(bcmul($waste, '100', 6), $fabric, 6), 1)];
    }

    /**
     * @return array{metres: string, fabric_kg: string|null, waste_kg: string|null}
     */
    private function figures(CuttingSpread $spread, object $material, int $layers): array
    {
        $metres = bcmul((string) $spread->getAttribute('length_m'), (string) $layers, 6);
        $width = $spread->getAttribute('width_cm') ?? $material->width_cm;
        $grammage = $spread->getAttribute('grammage_gsm') ?? $material->grammage_gsm;
        $fabric = $width === null || $grammage === null ? null : bcdiv(bcmul(bcmul($metres, (string) $width, 8), (string) $grammage, 8), '100000', 6);

        return ['metres' => CuttingWaste::round($metres, 3),
            'fabric_kg' => $fabric === null ? null : CuttingWaste::round($fabric, 3),
            'waste_kg' => $fabric === null ? null : CuttingWaste::round(bcdiv(bcmul($fabric, bcsub('100', (string) $spread->getAttribute('efficiency_pct'), 2), 8), '100', 6), 3)];
    }
}
