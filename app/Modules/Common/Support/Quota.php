<?php

namespace App\Modules\Common\Support;

/**
 * How full something is when its contents are mixed (a storage zone today; a quota of any kind tomorrow).
 *
 * A limit is written the way people know it: a weight, and/or how many lots of each kind fit ("40 bales, or 100 bags").
 * Each kind uses its own share of the room and the shares add up: 20 bales out of 40 and 50 bags out of 100 fill it.
 * When a weight limit is given too, it is a second ceiling: the higher of the two percentages is the one that counts.
 */
final class Quota
{
    /**
     * @param  array<string, int>  $maxCounts  kind => how many fit when the room holds only that kind
     * @param  array<string, int>  $counts  kind => how many are there
     * @return array{pct: float|null, weight_pct: float|null, count_pct: float|null, uncounted: int, remaining: array{kg: string|null, counts: array<string, int>}}
     */
    public static function usage(?string $maxKg, array $maxCounts, string $kg, array $counts): array
    {
        $maxCounts = array_filter($maxCounts, fn (int $max) => $max > 0);
        $hasWeight = $maxKg !== null && bccomp($maxKg, '0', 3) > 0;

        $share = 0.0;
        $uncounted = 0;
        foreach ($counts as $kind => $count) {
            if (isset($maxCounts[$kind])) {
                $share += $count / $maxCounts[$kind];
            } else {
                // No guess: a lot of a kind the limit does not mention is reported, not converted.
                $uncounted += $count;
            }
        }

        $countPct = $maxCounts === [] ? null : round($share * 100, 1);
        $weightPct = $hasWeight ? round((float) bcdiv(bcmul($kg, '100', 3), $maxKg, 3), 1) : null;
        $pct = $countPct === null && $weightPct === null ? null : max($countPct ?? 0.0, $weightPct ?? 0.0);

        // What still fits, in real terms: the free share by count, capped by nothing else — both ceilings are shown.
        $free = max(0.0, 1 - $share);
        $remaining = [];
        foreach ($maxCounts as $kind => $max) {
            $remaining[$kind] = (int) floor($free * $max + 1e-9);
        }

        return [
            'pct' => $pct,
            'weight_pct' => $weightPct,
            'count_pct' => $countPct,
            'uncounted' => $maxCounts === [] ? 0 : $uncounted,
            'remaining' => [
                'kg' => $hasWeight ? (bccomp($maxKg, $kg, 3) > 0 ? bcsub($maxKg, $kg, 3) : '0.000') : null,
                'counts' => $remaining,
            ],
        ];
    }

    /**
     * 'exceeded' above 100 %, 'warning' from the alert percentage, null below (or when there is no limit).
     */
    public static function level(?float $pct, int $warningPct): ?string
    {
        return match (true) {
            $pct === null => null,
            $pct > 100 => 'exceeded',
            $pct >= $warningPct => 'warning',
            default => null,
        };
    }
}
