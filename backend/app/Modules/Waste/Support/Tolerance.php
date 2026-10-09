<?php

namespace App\Modules\Waste\Support;

/**
 * Judges the gap between a reference quantity and what was actually measured, both in the same unit:
 * gap % = |measured − reference| ÷ reference × 100, compared with the limits of the rule that applies.
 *
 * It never invents a reference: without one (missing, zero or negative) there is no percentage, and the outcome
 * says so instead of pretending the gap is nil.
 */
final class Tolerance
{
    public const WITHIN = 'within';

    public const WARNING = 'warning';

    public const BLOCKING = 'blocking';

    public const NO_REFERENCE = 'no_reference';

    public const NOT_MEASURED = 'not_measured';

    /** From the least to the most serious: the outcome of a pickup is the worst of its lines. */
    private const SEVERITY = [self::WITHIN => 0, self::NOT_MEASURED => 1, self::NO_REFERENCE => 2, self::WARNING => 3, self::BLOCKING => 4];

    /**
     * @param  array{warning_pct?: string|null, blocking_pct?: string|null, direction?: string|null}  $rule
     * @return array{status: string, difference: string|null, pct: string|null, pct_abs: string|null}
     *                                                                                                `difference` and `pct` are signed (+ = more than the reference); `pct_abs` is the gap that is compared.
     */
    public static function evaluate(?string $reference, ?string $actual, array $rule): array
    {
        if ($actual === null || ! is_numeric($actual) || bccomp($actual, '0', 3) < 0) {
            return ['status' => self::NOT_MEASURED, 'difference' => null, 'pct' => null, 'pct_abs' => null];
        }
        if ($reference === null || ! is_numeric($reference) || bccomp($reference, '0', 3) <= 0) {
            $zero = $reference !== null && is_numeric($reference) && bccomp($reference, '0', 3) === 0;
            // Nothing expected and nothing measured is not a gap; anything measured against nothing has no percentage.
            $nothing = $zero && bccomp($actual, '0', 3) === 0;

            return ['status' => $nothing ? self::WITHIN : self::NO_REFERENCE, 'difference' => $zero ? bcadd($actual, '0', 3) : null, 'pct' => null, 'pct_abs' => null];
        }

        $difference = bcsub($actual, $reference, 3);
        $raw = bcdiv(bcmul($difference, '100', 6), $reference, 6);
        $gap = ltrim($raw, '-');
        $sign = bccomp($difference, '0', 3);
        $counts = match ($rule['direction'] ?? 'both') {
            'excess' => $sign > 0,
            'shortage' => $sign < 0,
            default => true,
        };
        $above = fn (?string $limit): bool => $counts && $limit !== null && bccomp($gap, $limit, 6) > 0;

        return [
            'status' => $above($rule['blocking_pct'] ?? null) ? self::BLOCKING : ($above($rule['warning_pct'] ?? null) ? self::WARNING : self::WITHIN),
            'difference' => $difference,
            'pct' => number_format((float) $raw, 2, '.', ''),
            'pct_abs' => number_format((float) $gap, 2, '.', ''),
        ];
    }

    /**
     * @param  list<string|null>  $statuses
     */
    public static function worst(array $statuses): ?string
    {
        $worst = null;
        foreach (array_filter($statuses) as $status) {
            if ($worst === null || self::SEVERITY[$status] > self::SEVERITY[$worst]) {
                $worst = $status;
            }
        }

        return $worst;
    }

    /** A line someone has to look at before the pickup is closed. */
    public static function needsReview(?string $status): bool
    {
        return in_array($status, [self::WARNING, self::BLOCKING, self::NO_REFERENCE], true);
    }
}
