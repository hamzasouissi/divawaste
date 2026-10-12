<?php

namespace App\Modules\Common\Support;

use App\Modules\Catalog\Support\UnitConversion;

/**
 * What a set of lots amounts to in the unit a rule is stated in. Stock limits and the minimums of a provider are two
 * different rules; they read the lots the same way, with the conversions of the waste type and nothing else.
 *
 * - kilograms, tonnes: the weight of the lot, when it has one;
 * - pieces, litres, cubic metres: what the lot declares in that dimension, otherwise its weight through the conversion
 *   its waste type states (weight of a piece, density). Without either, the lot cannot be counted;
 * - packages: the packages of the lots packed in the packaging the rule names. A lot is as many packages as it says,
 *   never one because it is one lot; lots in another packaging and loose lots are outside the rule and are named.
 *
 * A lot that cannot be counted is listed, never read as zero.
 */
final class Measure
{
    public const PACKAGE = 'package';

    public const UNITS = ['kg', 't', 'piece', 'l', 'm3', self::PACKAGE];

    /** 'mass', 'count', 'volume' or 'package'. */
    public static function dimension(string $unit): ?string
    {
        return $unit === self::PACKAGE ? self::PACKAGE : (in_array($unit, self::UNITS, true) ? UnitConversion::dimension($unit) : null);
    }

    /** Decimals a quantity in this unit is compared with: packages are whole. */
    public static function scale(string $unit): int
    {
        return $unit === self::PACKAGE ? 0 : 3;
    }

    /**
     * Whether a waste type can be counted in a unit at all: always by weight; in pieces or in volume when it is counted
     * that way, or when it says what a piece weighs or how dense it is.
     */
    public static function suits(string $unit, ?string $typeUnit, ?string $densityKgM3, ?string $unitWeightKg): bool
    {
        return match (self::dimension($unit)) {
            'mass', self::PACKAGE => true,
            'count' => UnitConversion::dimension($typeUnit) === 'count' || self::positive($unitWeightKg),
            'volume' => UnitConversion::dimension($typeUnit) === 'volume' || self::positive($densityKgM3),
            default => false,
        };
    }

    /**
     * @param  iterable<object>  $lots  each with lot_number, kg, weight_basis, quantity, unit, density_kg_m3, unit_weight_kg, packaging (code, '' or null when loose) and packages
     * @param  bool  $measuredOnly  an estimated weight is then not a figure to decide on
     * @return array{used: string, counted: int, missing: list<string>, outside: list<string>}
     */
    public static function total(iterable $lots, string $unit, ?string $packaging = null, bool $measuredOnly = false): array
    {
        $scale = self::scale($unit);
        $total = ['used' => bcadd('0', '0', $scale), 'counted' => 0, 'missing' => [], 'outside' => []];
        foreach ($lots as $lot) {
            $number = (string) $lot->lot_number;
            if ($unit === self::PACKAGE && (string) ($lot->packaging ?? '') !== (string) $packaging) {
                $total['outside'][] = $number;

                continue;
            }
            $value = self::of($lot, $unit, $measuredOnly);
            if ($value === null) {
                $total['missing'][] = $number;

                continue;
            }
            $total['used'] = bcadd($total['used'], $value, $scale);
            $total['counted']++;
        }

        return $total;
    }

    private static function of(object $lot, string $unit, bool $measuredOnly): ?string
    {
        if ($unit === self::PACKAGE) {
            return $lot->packages === null ? null : (string) (int) $lot->packages;
        }
        $kg = $lot->kg === null || ($measuredOnly && ($lot->weight_basis ?? 'measured') === 'estimated') ? null : (string) $lot->kg;
        $dimension = UnitConversion::dimension($unit);
        if ($dimension === 'mass') {
            return $kg === null ? null : UnitConversion::convert($kg, 'kg', $unit);
        }
        if ($lot->quantity !== null && UnitConversion::dimension($lot->unit ?? null) === $dimension && ($lot->unit ?? null) !== null) {
            return UnitConversion::convert((string) $lot->quantity, $lot->unit, $unit);
        }

        return $kg === null ? null : UnitConversion::convert($kg, 'kg', $unit,
            $lot->density_kg_m3 === null ? null : (string) $lot->density_kg_m3, $lot->unit_weight_kg === null ? null : (string) $lot->unit_weight_kg);
    }

    private static function positive(?string $value): bool
    {
        return $value !== null && bccomp($value, '0', 6) > 0;
    }
}
