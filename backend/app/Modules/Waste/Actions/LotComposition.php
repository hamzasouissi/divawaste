<?php

namespace App\Modules\Waste\Actions;

use App\Modules\Waste\Models\WasteLot;
use App\Modules\Waste\Models\WasteLotComposition;
use Illuminate\Support\Facades\DB;

/**
 * Writes a lot's composition rows and its denormalized label ("CO 80% / PES 20%").
 */
final class LotComposition
{
    /**
     * @param  list<array{material: string, pct: string}>  $lines
     */
    public static function apply(WasteLot $lot, array $lines): void
    {
        $materials = DB::table('materials')->whereIn('code', array_column($lines, 'material'))->pluck('id', 'code');
        $labels = [];
        foreach ($lines as $line) {
            $pct = bcadd((string) $line['pct'], '0', 2);
            WasteLotComposition::query()->create(['waste_lot_id' => $lot->id, 'material_id' => $materials[$line['material']], 'percentage' => $pct]);
            $labels[] = $line['material'].' '.rtrim(rtrim($pct, '0'), '.').'%';
        }

        $lot->forceFill(['composition_label' => $labels === [] ? null : implode(' / ', $labels)])->saveQuietly();
    }

    /**
     * Weight-weighted average of several lots' compositions (grouping).
     *
     * @param  list<WasteLot>  $lots
     * @return list<array{material: string, pct: string}>
     */
    public static function weightedAverage(array $lots, string $totalKg): array
    {
        $weighted = [];
        foreach ($lots as $lot) {
            $rows = DB::table('waste_lot_compositions as c')->join('materials as m', 'm.id', '=', 'c.material_id')
                ->where('c.waste_lot_id', $lot->id)->get(['m.code', 'c.percentage']);
            foreach ($rows as $row) {
                $weighted[$row->code] = bcadd($weighted[$row->code] ?? '0', bcmul((string) $lot->net_weight_kg, (string) $row->percentage, 6), 6);
            }
        }

        if ($weighted === [] || bccomp($totalKg, '0', 3) === 0) {
            return [];
        }

        $lines = [];
        foreach ($weighted as $code => $mass) {
            $lines[] = ['material' => $code, 'pct' => bcdiv($mass, $totalKg, 2)];
        }
        // Rounding remainder goes to the largest share so the total stays 100.00 (only when inputs had full compositions).
        usort($lines, fn ($a, $b) => bccomp($b['pct'], $a['pct'], 2));
        $sum = array_reduce($lines, fn ($carry, $line) => bcadd($carry, $line['pct'], 2), '0');
        $gap = bcsub('100', $sum, 2);
        if (bccomp(ltrim($gap, '-'), '1', 2) < 0) {
            $lines[0]['pct'] = bcadd($lines[0]['pct'], $gap, 2);
        }

        return $lines;
    }
}
