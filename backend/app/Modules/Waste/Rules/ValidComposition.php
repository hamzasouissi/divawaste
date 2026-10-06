<?php

namespace App\Modules\Waste\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * [{material, pct}] with distinct materials and percentages totalling 100.00 (M1-04).
 */
final class ValidComposition implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value) || $value === []) {
            $fail('Composition must be a non-empty list.');

            return;
        }

        $sum = '0';
        $materials = [];
        foreach ($value as $line) {
            if (! is_array($line) || ! isset($line['material'], $line['pct']) || ! is_numeric($line['pct']) || $line['pct'] <= 0 || $line['pct'] > 100) {
                $fail('Each composition line needs a material and a percentage in (0, 100].');

                return;
            }
            $materials[] = $line['material'];
            $sum = bcadd($sum, (string) $line['pct'], 2);
        }

        if (count($materials) !== count(array_unique($materials))) {
            $fail('Materials must be distinct.');
        } elseif (bccomp($sum, '100', 2) !== 0) {
            $fail('Composition percentages must total 100.');
        }
    }
}
