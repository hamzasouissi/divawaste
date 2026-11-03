<?php

namespace App\Modules\Waste\Actions;

use App\Modules\Waste\Models\WasteLot;
use App\Modules\Waste\Models\WasteLotComposition;
use Illuminate\Support\Facades\DB;

/**
 * Writes a lot's composition rows, its denormalized label ("CO 80% / PES 20%") and where it comes from: typed by a
 * person, copied from a raw material or from the defaults of the waste type, averaged from grouped lots, or kept from
 * the lot it was split from. A company that does not follow its production enters it on the lot itself.
 */
final class LotComposition
{
    public const SOURCES = ['entered', 'raw_material', 'waste_type', 'grouping', 'split'];

    /**
     * @param  list<array{material: string, pct: string}>  $lines
     * @param  string|null  $source  one of SOURCES; ignored when there is no line
     */
    public static function apply(WasteLot $lot, array $lines, ?string $source = null): void
    {
        $materials = DB::table('materials')->whereIn('code', array_column($lines, 'material'))->pluck('id', 'code');
        $labels = [];
        foreach ($lines as $line) {
            $pct = bcadd((string) $line['pct'], '0', 2);
            WasteLotComposition::query()->create(['waste_lot_id' => $lot->id, 'material_id' => $materials[$line['material']], 'percentage' => $pct]);
            $labels[] = $line['material'].' '.rtrim(rtrim($pct, '0'), '.').'%';
        }

        $lot->forceFill(['composition_label' => $labels === [] ? null : implode(' / ', $labels), 'composition_source' => $labels === [] ? null : $source])->saveQuietly();
    }

    /**
     * Replaces what the lot is made of (an empty list = not known). The pickup lines of a lot that has not left carry
     * the new composition; what a lot that left carried stays with its pickup and its slip.
     *
     * @param  list<array{material: string, pct: string}>  $lines
     */
    public static function replace(WasteLot $lot, array $lines): void
    {
        WasteLotComposition::query()->where('waste_lot_id', $lot->id)->delete();
        self::apply($lot, $lines, 'entered');
        DB::table('pickup_lots')->where('waste_lot_id', $lot->id)->where('is_active_flag', true)->where('line_status', 'planned')
            ->update(['composition_snapshot' => self::encode($lot->id), 'updated_at' => now()]);
    }

    /**
     * The composition of a lot, largest share first: [{material, pct}].
     *
     * @return list<array{material: string, pct: string}>
     */
    public static function of(int $lotId): array
    {
        return DB::table('waste_lot_compositions as c')->join('materials as m', 'm.id', '=', 'c.material_id')
            ->where('c.waste_lot_id', $lotId)->orderByDesc('c.percentage')->orderBy('m.code')->get(['m.code', 'c.percentage'])
            ->map(fn ($row) => ['material' => (string) $row->code, 'pct' => (string) $row->percentage])->values()->all();
    }

    /**
     * The composition as kept on a pickup line, or null when the lot has none.
     */
    public static function encode(int $lotId): ?string
    {
        $lines = self::of($lotId);

        return $lines === [] ? null : (string) json_encode($lines);
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
