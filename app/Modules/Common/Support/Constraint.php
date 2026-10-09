<?php

namespace App\Modules\Common\Support;

/**
 * One limit, checked on its own: a quantity in one unit against a maximum in the same unit.
 *
 * A weight limit, a number of packages and a number of lots are three questions with three answers. Each is evaluated
 * here separately and they are never merged into one percentage: 90 % of a weight and 103 % of a number of bags say
 * nothing about each other. What an operation does with the answers (refuse, alert, nothing) is decided by its own rules,
 * from `overall()`.
 *
 * - respected: the limit is met, and every quantity it needs is known;
 * - warning: met, but the alert percentage is reached;
 * - exceeded: what is known is already above the limit (lots that could not be counted are named too);
 * - unverifiable: what is known is below the limit, but a quantity is missing. Missing is never read as zero.
 */
final class Constraint
{
    public const RESPECTED = 'respected';

    public const WARNING = 'warning';

    public const EXCEEDED = 'exceeded';

    public const UNVERIFIABLE = 'unverifiable';

    /** How many lot numbers a result names; the count is always complete. */
    private const NAMED = 50;

    /**
     * A quantity against a maximum in the same unit.
     *
     * @param  list<string>  $missing  lot numbers whose quantity for this limit is unknown
     * @return array<string, mixed>
     */
    public static function quantity(string $key, string $unit, string $used, string $max, int $warningPct, array $missing = [], bool $blocking = false, int $scale = 3): array
    {
        $over = bccomp($used, $max, $scale) > 0;
        $pct = bccomp($max, '0', $scale) > 0 ? round((float) bcdiv(bcmul($used, '100', $scale), $max, $scale + 1), 1) : null;

        return self::result($key, $unit, $pct, $over, $warningPct, $missing, $blocking) + [
            'used' => $scale === 0 ? (int) $used : $used,
            'max' => $scale === 0 ? (int) $max : $max,
            'remaining' => $over ? ($scale === 0 ? 0 : bcadd('0', '0', $scale)) : ($scale === 0 ? (int) bcsub($max, $used, 0) : bcsub($max, $used, $scale)),
            'excess' => $over ? ($scale === 0 ? (int) bcsub($used, $max, 0) : bcsub($used, $max, $scale)) : null,
        ];
    }

    /**
     * Packages against a room stated per packaging ("100 bags or 50 boxes"). The packagings share one room: each uses
     * the share its own count takes of its own maximum, and the shares add up — 50 bags and 25 boxes fill it. This is
     * one limit in one dimension (the room), so one percentage is right here; it is never mixed with a weight.
     *
     * @param  array<string, int>  $maxCounts  packaging => how many fit when the room holds only that packaging
     * @param  array<string, int>  $counts  packaging => packages present ('' and packagings without a maximum are ignored here)
     * @param  list<string>  $missing  lot numbers this limit cannot count
     * @return array<string, mixed>
     */
    public static function packages(string $key, array $maxCounts, array $counts, int $warningPct, array $missing = [], bool $blocking = false): array
    {
        $maxCounts = array_filter($maxCounts, fn (int $max) => $max > 0);
        $share = 0.0;
        foreach ($maxCounts as $kind => $max) {
            $share += ($counts[$kind] ?? 0) / $max;
        }
        $over = $share > 1 + 1e-9;
        $free = max(0.0, 1 - $share);
        $single = count($maxCounts) === 1;

        $lines = [];
        foreach ($maxCounts as $kind => $max) {
            $used = (int) ($counts[$kind] ?? 0);
            $lines[] = [
                'packaging_type' => (string) $kind, 'used' => $used, 'max' => $max,
                // What still fits if only this packaging is added.
                'remaining' => (int) floor($free * $max + 1e-9),
                // With one packaging the excess is a plain number of packages; with several it is a share of the room.
                'excess' => $over && $single ? $used - $max : null,
            ];
        }

        return self::result($key, 'package', round($share * 100, 1), $over, $warningPct, $missing, $blocking) + ['lines' => $lines];
    }

    /**
     * What the answers mean together: the most serious one, and whether a blocking limit failed. Exceeded and
     * unverifiable both fail a blocking limit; they stay two different answers.
     *
     * @param  list<array<string, mixed>>  $constraints
     * @return array{outcome: string|null, blocked: bool, failing: list<string>}
     */
    public static function overall(array $constraints): array
    {
        $rank = [self::RESPECTED => 0, self::WARNING => 1, self::UNVERIFIABLE => 2, self::EXCEEDED => 3];
        $outcome = null;
        $failing = [];
        foreach ($constraints as $constraint) {
            if ($outcome === null || $rank[$constraint['outcome']] > $rank[$outcome]) {
                $outcome = $constraint['outcome'];
            }
            if ($constraint['blocking'] && in_array($constraint['outcome'], [self::EXCEEDED, self::UNVERIFIABLE], true)) {
                $failing[] = (string) $constraint['key'];
            }
        }

        return ['outcome' => $outcome, 'blocked' => $failing !== [], 'failing' => $failing];
    }

    /**
     * @param  list<string>  $missing
     * @return array<string, mixed>
     */
    private static function result(string $key, string $unit, ?float $pct, bool $over, int $warningPct, array $missing, bool $blocking): array
    {
        $missing = array_values(array_unique($missing));

        return [
            'key' => $key,
            'unit' => $unit,
            'pct' => $pct,
            'outcome' => match (true) {
                $over => self::EXCEEDED,
                $missing !== [] => self::UNVERIFIABLE,
                $pct !== null && $pct >= $warningPct => self::WARNING,
                default => self::RESPECTED,
            },
            'blocking' => $blocking,
            // Lots this limit could not count: named up to a point, always counted in full.
            'missing_count' => count($missing),
            'missing_lots' => array_slice($missing, 0, self::NAMED),
        ];
    }
}
