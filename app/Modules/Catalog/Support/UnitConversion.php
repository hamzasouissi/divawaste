<?php

namespace App\Modules\Catalog\Support;

/**
 * M1-05: every weight is stored in kg. Conversion to tonnes is always possible; to m³ it needs the
 * density of the waste type, to pieces its unit weight. Returns null when the type cannot be converted.
 *
 * Two quantities are comparable only in the same dimension (kg with tonnes, litres with m³, pieces with pieces).
 * Pieces or litres become kilograms only through the conversion the waste type states — never by guessing.
 */
final class UnitConversion
{
    /** unit => [dimension, factor to the base unit of the dimension (kg, m³, piece)]. */
    private const UNITS = ['kg' => ['mass', '1'], 't' => ['mass', '1000'], 'm3' => ['volume', '1'], 'l' => ['volume', '0.001'], 'piece' => ['count', '1']];

    public static function fromKg(string $kg, ?string $unit, ?string $densityKgM3 = null, ?string $unitWeightKg = null): ?string
    {
        return match ($unit) {
            'kg', null => bcadd($kg, '0', 3),
            't' => bcdiv($kg, '1000', 3),
            'm3' => self::positive($densityKgM3) ? bcdiv($kg, (string) $densityKgM3, 3) : null,
            'l' => self::positive($densityKgM3) ? bcdiv(bcmul($kg, '1000', 6), (string) $densityKgM3, 3) : null,
            'piece' => self::positive($unitWeightKg) ? bcdiv($kg, (string) $unitWeightKg, 0) : null,
            default => null,
        };
    }

    /** 'mass', 'volume' or 'count'; null for a unit waste is not counted in. */
    public static function dimension(?string $unit): ?string
    {
        return self::UNITS[$unit ?? 'kg'][0] ?? null;
    }

    public static function comparable(?string $unit, ?string $other): bool
    {
        return self::dimension($unit) !== null && self::dimension($unit) === self::dimension($other);
    }

    /**
     * A quantity expressed in another unit. Between dimensions it needs the conversion of the waste type
     * (weight of one piece, density); without it the answer is null: the two quantities cannot be compared.
     */
    public static function convert(string $quantity, ?string $from, ?string $to, ?string $densityKgM3 = null, ?string $unitWeightKg = null): ?string
    {
        [$fromDimension, $toDimension] = [self::dimension($from), self::dimension($to)];
        if ($fromDimension === null || $toDimension === null) {
            return null;
        }
        $base = bcmul($quantity, self::UNITS[$from ?? 'kg'][1], 9);
        if ($fromDimension !== $toDimension) {
            $kg = match ($fromDimension) {
                'mass' => $base,
                'count' => self::positive($unitWeightKg) ? bcmul($base, (string) $unitWeightKg, 9) : null,
                default => self::positive($densityKgM3) ? bcmul($base, (string) $densityKgM3, 9) : null,
            };
            $base = match (true) {
                $kg === null => null,
                $toDimension === 'mass' => $kg,
                $toDimension === 'count' => self::positive($unitWeightKg) ? bcdiv($kg, (string) $unitWeightKg, 9) : null,
                default => self::positive($densityKgM3) ? bcdiv($kg, (string) $densityKgM3, 9) : null,
            };
            if ($base === null) {
                return null;
            }
        }

        return bcdiv($base, self::UNITS[$to ?? 'kg'][1], 3);
    }

    /** The weight of a counted or measured quantity, when the waste type says how to compute it. */
    public static function toKg(string $quantity, ?string $unit, ?string $densityKgM3 = null, ?string $unitWeightKg = null): ?string
    {
        return self::convert($quantity, $unit, 'kg', $densityKgM3, $unitWeightKg);
    }

    private static function positive(?string $value): bool
    {
        return $value !== null && bccomp($value, '0', 6) > 0;
    }
}
