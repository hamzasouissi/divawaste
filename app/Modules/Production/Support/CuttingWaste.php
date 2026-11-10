<?php

namespace App\Modules\Production\Support;

use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * The cutting waste an article is expected to leave, before anything is cut.
 *
 * The consumption per piece of a fabric is the gross one (marker length ÷ pieces in the marker): the waste is inside it.
 * Its weight is metres × laize × grammage, and what the placement does not use, (100 − efficiency) %, is the waste:
 *
 *     waste (kg per piece) = consumption (m) × laize (cm) / 100 × grammage (g/m²) / 1000 × (100 − efficiency) / 100
 *
 * Only a material counted in metres with its laize and its grammage is cut from a placement this way; supplies
 * (buttons, labels, threads) are not, and give no figure rather than a wrong one. The efficiency is the line's, or the
 * company's default.
 */
final class CuttingWaste
{
    /** A usual placement efficiency, until the company says its own. */
    public const DEFAULT_EFFICIENCY = '80.00';

    public function __construct(private readonly TenantContext $context) {}

    public static function companyDefault(?Company $company): string
    {
        return (string) ($company?->settings['cutting_efficiency_pct'] ?? self::DEFAULT_EFFICIENCY);
    }

    /** A positive figure rounded to the nearest at this scale (bcmath only truncates). */
    public static function round(string $value, int $scale): string
    {
        return bcadd($value, '0.'.str_repeat('0', $scale).'5', $scale);
    }

    public function defaultEfficiency(): string
    {
        return self::companyDefault(Company::query()->find($this->context->companyId()));
    }

    /**
     * One line of a composition: the fabric consumed and its expected waste, per piece, in kilograms.
     *
     * @return array{efficiency_pct: string|null, efficiency_applied: string|null, fabric_kg: string|null, waste_kg: string|null}
     */
    public function line(string $quantity, ?string $unit, mixed $widthCm, mixed $grammageGsm, mixed $efficiency, string $default): array
    {
        $own = $efficiency === null ? null : bcadd((string) $efficiency, '0', 2);
        if ($unit !== 'm' || $widthCm === null || $grammageGsm === null) {
            return ['efficiency_pct' => $own, 'efficiency_applied' => null, 'fabric_kg' => null, 'waste_kg' => null];
        }
        $applied = $own ?? $default;
        $fabric = bcdiv(bcmul(bcmul($quantity, (string) $widthCm, 8), (string) $grammageGsm, 8), '100000', 6);

        return ['efficiency_pct' => $own, 'efficiency_applied' => $applied, 'fabric_kg' => $fabric,
            'waste_kg' => bcdiv(bcmul($fabric, bcsub('100', $applied, 2), 8), '100', 6)];
    }

    /**
     * The expected waste of one piece of each article, in kilograms; null for an article with no fabric it can be
     * computed for.
     *
     * @param  list<int>  $articleIds
     * @return array<int, array{fabric_kg: string, waste_kg: string}>
     */
    public function perPiece(array $articleIds): array
    {
        if ($articleIds === []) {
            return [];
        }
        $default = $this->defaultEfficiency();
        $rows = DB::table('article_materials as am')->join('raw_materials as m', 'm.id', '=', 'am.raw_material_id')->join('units as u', 'u.id', '=', 'm.unit_id')
            ->whereIn('am.article_id', $articleIds)->get(['am.article_id', 'am.quantity_per_piece', 'am.efficiency_pct', 'u.code as unit', 'm.width_cm', 'm.grammage_gsm']);
        $out = [];
        foreach ($rows as $row) {
            $line = $this->line((string) $row->quantity_per_piece, $row->unit, $row->width_cm, $row->grammage_gsm, $row->efficiency_pct, $default);
            if ($line['waste_kg'] === null) {
                continue;
            }
            $sum = $out[$row->article_id] ?? ['fabric_kg' => '0', 'waste_kg' => '0'];
            $out[$row->article_id] = ['fabric_kg' => bcadd($sum['fabric_kg'], $line['fabric_kg'], 6), 'waste_kg' => bcadd($sum['waste_kg'], $line['waste_kg'], 6)];
        }

        return $out;
    }
}
