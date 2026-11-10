<?php

namespace App\Modules\Production\Support;

use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * The cutting waste an article is expected to leave, before anything is cut, and the type of waste it is.
 *
 * The consumption per piece of a fabric is the gross one (marker length ÷ pieces in the marker): the waste is inside it.
 * Its weight is metres × laize × grammage, and what the placement does not use, (100 − efficiency) %, is the waste:
 *
 *     waste (kg per piece) = consumption (m) × laize (cm) / 100 × grammage (g/m²) / 1000 × (100 − efficiency) / 100
 *
 * Only a material counted in metres with its laize and its grammage is cut from a placement this way; supplies
 * (buttons, labels, threads) are not, and give no figure rather than a wrong one. The efficiency is the line's, or the
 * company's default. The waste is the type the material says it leaves, or else the company's "Chutes de coupe".
 */
final class CuttingWaste
{
    /** A usual placement efficiency, until the company says its own. */
    public const DEFAULT_EFFICIENCY = '80.00';

    /** The catalogue item a fabric leaves when the material does not say otherwise. */
    public const CUTTING_SCRAPS = 'cutting_scraps';

    /** @var array<int, int|null> company id => its cutting scraps type */
    private array $scraps = [];

    /** @var array<int, array{ulid: string, name: string}|null> */
    private array $types = [];

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

    /** The company's "Chutes de coupe": its active type made from that catalogue item, the oldest when it has several. */
    public function scrapsType(): ?int
    {
        $companyId = (int) $this->context->companyId();
        if (! array_key_exists($companyId, $this->scraps)) {
            $id = DB::table('waste_types as t')->join('waste_catalog_items as i', 'i.id', '=', 't.waste_catalog_item_id')
                ->where('t.company_id', $companyId)->where('i.code', self::CUTTING_SCRAPS)->where('t.is_active', true)->orderBy('t.id')->value('t.id');
            $this->scraps[$companyId] = $id === null ? null : (int) $id;
        }

        return $this->scraps[$companyId];
    }

    /** @return array{ulid: string, name: string}|null */
    public function type(?int $id): ?array
    {
        if ($id === null) {
            return null;
        }
        if (! array_key_exists($id, $this->types)) {
            $row = DB::table('waste_types')->where('id', $id)->first(['ulid', 'name']);
            $this->types[$id] = $row === null ? null : ['ulid' => $row->ulid, 'name' => $row->name];
        }

        return $this->types[$id];
    }

    /**
     * One line of a composition: the fabric consumed and its expected waste, per piece, in kilograms, and the type of
     * waste it is. `inherited`: the material does not say its waste, the company's cutting scraps are assumed.
     *
     * @return array{efficiency_pct: string|null, efficiency_applied: string|null, fabric_kg: string|null, waste_kg: string|null, waste_type_id: int|null, inherited: bool}
     */
    public function line(string $quantity, ?string $unit, mixed $widthCm, mixed $grammageGsm, mixed $efficiency, string $default, mixed $wasteTypeId = null): array
    {
        $own = $efficiency === null ? null : bcadd((string) $efficiency, '0', 2);
        $type = $wasteTypeId === null ? null : (int) $wasteTypeId;
        if ($unit !== 'm' || $widthCm === null || $grammageGsm === null) {
            return ['efficiency_pct' => $own, 'efficiency_applied' => null, 'fabric_kg' => null, 'waste_kg' => null, 'waste_type_id' => $type, 'inherited' => false];
        }
        $applied = $own ?? $default;
        $fabric = bcdiv(bcmul(bcmul($quantity, (string) $widthCm, 8), (string) $grammageGsm, 8), '100000', 6);

        return ['efficiency_pct' => $own, 'efficiency_applied' => $applied, 'fabric_kg' => $fabric,
            'waste_kg' => bcdiv(bcmul($fabric, bcsub('100', $applied, 2), 8), '100', 6),
            'waste_type_id' => $type ?? $this->scrapsType(), 'inherited' => $type === null];
    }

    /**
     * The expected waste of one piece of each article, in kilograms, in all and by type of waste (0: a type nobody
     * said and the company has no cutting scraps type for); an article with no fabric it can be computed for is absent.
     *
     * @param  list<int>  $articleIds
     * @return array<int, array{fabric_kg: string, waste_kg: string, by_type: array<int, string>}>
     */
    public function perPiece(array $articleIds): array
    {
        if ($articleIds === []) {
            return [];
        }
        $default = $this->defaultEfficiency();
        $rows = DB::table('article_materials as am')->join('raw_materials as m', 'm.id', '=', 'am.raw_material_id')->join('units as u', 'u.id', '=', 'm.unit_id')
            ->whereIn('am.article_id', $articleIds)->get(['am.article_id', 'am.quantity_per_piece', 'am.efficiency_pct', 'u.code as unit', 'm.width_cm', 'm.grammage_gsm', 'm.waste_type_id']);
        $out = [];
        foreach ($rows as $row) {
            $line = $this->line((string) $row->quantity_per_piece, $row->unit, $row->width_cm, $row->grammage_gsm, $row->efficiency_pct, $default, $row->waste_type_id);
            if ($line['waste_kg'] === null) {
                continue;
            }
            $sum = $out[$row->article_id] ?? ['fabric_kg' => '0', 'waste_kg' => '0', 'by_type' => []];
            $type = $line['waste_type_id'] ?? 0;
            $sum['by_type'][$type] = bcadd($sum['by_type'][$type] ?? '0', $line['waste_kg'], 6);
            $out[$row->article_id] = ['fabric_kg' => bcadd($sum['fabric_kg'], $line['fabric_kg'], 6), 'waste_kg' => bcadd($sum['waste_kg'], $line['waste_kg'], 6), 'by_type' => $sum['by_type']];
        }

        return $out;
    }
}
