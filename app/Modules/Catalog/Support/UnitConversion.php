<?php

namespace App\Modules\Catalog\Support;

/**
 * M1-05: every weight is stored in kg. Conversion to tonnes is always possible; to m³ it needs the
 * density of the waste type, to pieces its unit weight. Returns null when the type cannot be converted.
 */
final class UnitConversion
{
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

    private static function positive(?string $value): bool
    {
        return $value !== null && bccomp($value, '0', 6) > 0;
    }
}
