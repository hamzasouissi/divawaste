<?php

namespace App\Modules\Catalog\Support;

/**
 * Conversions between the ways a colour is written (HEX, RGB, CIE Lab) and the distance between two colours.
 * Lab values are computed from sRGB under the D65 illuminant: they describe the colour a screen shows, not a
 * measurement of the physical sample.
 */
final class ColorMath
{
    /**
     * "#0a6c74", "0A6C74", " #abc " → "#0A6C74" / "#AABBCC". Anything else is not a colour.
     */
    public static function normalizeHex(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^#?([0-9a-f]{3}|[0-9a-f]{6})$/i', trim($value), $match) !== 1) {
            return null;
        }
        $digits = strtoupper($match[1]);

        return '#'.(strlen($digits) === 3 ? $digits[0].$digits[0].$digits[1].$digits[1].$digits[2].$digits[2] : $digits);
    }

    /**
     * @return array{r: int, g: int, b: int}
     */
    public static function rgb(string $hex): array
    {
        return ['r' => (int) hexdec(substr($hex, 1, 2)), 'g' => (int) hexdec(substr($hex, 3, 2)), 'b' => (int) hexdec(substr($hex, 5, 2))];
    }

    public static function hex(int $r, int $g, int $b): string
    {
        return sprintf('#%02X%02X%02X', $r, $g, $b);
    }

    /**
     * One channel as written in a file or a form: an integer from 0 to 255, possibly as a string.
     */
    public static function channel(mixed $value): ?int
    {
        if (is_string($value) && preg_match('/^\s*\d{1,3}\s*$/', $value) === 1) {
            $value = (int) $value;
        }

        return is_int($value) && $value >= 0 && $value <= 255 ? $value : null;
    }

    /**
     * @return array{l: float, a: float, b: float}
     */
    public static function lab(string $hex): array
    {
        $linear = array_map(function (int $channel): float {
            $c = $channel / 255;

            return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, array_values(self::rgb($hex)));

        // sRGB → XYZ (D65), then relative to the D65 white point.
        $x = (0.4124564 * $linear[0] + 0.3575761 * $linear[1] + 0.1804375 * $linear[2]) / 0.95047;
        $y = 0.2126729 * $linear[0] + 0.7151522 * $linear[1] + 0.0721750 * $linear[2];
        $z = (0.0193339 * $linear[0] + 0.1191920 * $linear[1] + 0.9503041 * $linear[2]) / 1.08883;
        $f = fn (float $t): float => $t > 216 / 24389 ? $t ** (1 / 3) : (24389 / 27 * $t + 16) / 116;

        return ['l' => round(116 * $f($y) - 16, 3), 'a' => round(500 * ($f($x) - $f($y)), 3), 'b' => round(200 * ($f($y) - $f($z)), 3)];
    }

    /**
     * CIEDE2000: the distance between two colours as the eye judges it. Around 1 is barely visible, 2 to 5 is a
     * close neighbour, above 10 is another colour.
     *
     * @param  array{l: float, a: float, b: float}  $one
     * @param  array{l: float, a: float, b: float}  $two
     */
    public static function deltaE(array $one, array $two): float
    {
        [$l1, $a1, $b1] = [$one['l'], $one['a'], $one['b']];
        [$l2, $a2, $b2] = [$two['l'], $two['a'], $two['b']];

        $meanC = (sqrt($a1 ** 2 + $b1 ** 2) + sqrt($a2 ** 2 + $b2 ** 2)) / 2;
        $g = 0.5 * (1 - sqrt($meanC ** 7 / ($meanC ** 7 + 25 ** 7)));
        $a1p = (1 + $g) * $a1;
        $a2p = (1 + $g) * $a2;
        $c1p = sqrt($a1p ** 2 + $b1 ** 2);
        $c2p = sqrt($a2p ** 2 + $b2 ** 2);
        $hue = function (float $b, float $a): float {
            if ($a == 0.0 && $b == 0.0) {
                return 0.0;
            }
            $h = rad2deg(atan2($b, $a));

            return $h < 0 ? $h + 360 : $h;
        };
        $h1p = $hue($b1, $a1p);
        $h2p = $hue($b2, $a2p);

        $dL = $l2 - $l1;
        $dC = $c2p - $c1p;
        $dh = 0.0;
        if ($c1p * $c2p != 0.0) {
            $dh = $h2p - $h1p;
            $dh = $dh > 180 ? $dh - 360 : ($dh < -180 ? $dh + 360 : $dh);
        }
        $dH = 2 * sqrt($c1p * $c2p) * sin(deg2rad($dh / 2));

        $meanL = ($l1 + $l2) / 2;
        $meanCp = ($c1p + $c2p) / 2;
        $meanH = $h1p + $h2p;
        if ($c1p * $c2p != 0.0) {
            $meanH = abs($h1p - $h2p) <= 180 ? ($h1p + $h2p) / 2 : (($h1p + $h2p) < 360 ? ($h1p + $h2p + 360) / 2 : ($h1p + $h2p - 360) / 2);
        }

        $t = 1 - 0.17 * cos(deg2rad($meanH - 30)) + 0.24 * cos(deg2rad(2 * $meanH)) + 0.32 * cos(deg2rad(3 * $meanH + 6)) - 0.20 * cos(deg2rad(4 * $meanH - 63));
        $sL = 1 + 0.015 * ($meanL - 50) ** 2 / sqrt(20 + ($meanL - 50) ** 2);
        $sC = 1 + 0.045 * $meanCp;
        $sH = 1 + 0.015 * $meanCp * $t;
        $rT = -2 * sqrt($meanCp ** 7 / ($meanCp ** 7 + 25 ** 7)) * sin(deg2rad(60 * exp(-((($meanH - 275) / 25) ** 2))));

        return sqrt(($dL / $sL) ** 2 + ($dC / $sC) ** 2 + ($dH / $sH) ** 2 + $rT * ($dC / $sC) * ($dH / $sH));
    }
}
