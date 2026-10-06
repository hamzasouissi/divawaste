<?php

namespace App\Modules\Billing\Support;

/**
 * Exact decimal money arithmetic (bcmath on numeric strings, never floats). Half-up rounding.
 */
final class Amount
{
    public static function multiply(string $a, string $b, int $scale = 3): string
    {
        return self::round(bcmul($a, $b, $scale + 4), $scale);
    }

    public static function percent(string $base, string $ratePct, int $scale = 3): string
    {
        return self::round(bcdiv(bcmul($base, $ratePct, $scale + 6), '100', $scale + 4), $scale);
    }

    public static function add(string $a, string $b, int $scale = 3): string
    {
        return bcadd($a, $b, $scale);
    }

    public static function compare(string $a, string $b): int
    {
        return bccomp($a, $b, 6);
    }

    public static function round(string $value, int $scale = 3): string
    {
        $half = '0.'.str_repeat('0', $scale).'5';

        return bcadd($value, bccomp($value, '0', $scale + 4) < 0 ? '-'.$half : $half, $scale);
    }
}
