<?php

namespace App\Modules\Production\Support;

use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * The waste an article is expected to leave, before anything is cut or weighed, by type of waste.
 *
 * Each raw material says the waste it leaves, line by line, each line with its own calculation (per piece):
 *
 *     cutting    kg of fabric × (100 − efficiency) % × share %     a fabric cut from a placement
 *     loss_rate  kg consumed × rate %                             thread lost at sewing, chemicals
 *     per_unit   quantity consumed × kg per unit                  an empty cone per cone used
 *     whole      kg consumed                                      packaging thrown away
 *
 * The consumption per piece of a fabric is the gross one (marker length ÷ pieces in the marker): the waste is inside it.
 * Its weight is metres × laize × grammage; kilograms are as they are; pieces weigh the material's unit weight. The
 * efficiency is the line's, or the company's default. A fabric that says nothing leaves all the company's "Chutes de
 * coupe"; anything that cannot be computed (no laize, no unit weight, no rate) gives no figure rather than a wrong one.
 */
final class CuttingWaste
{
    /** A usual placement efficiency, until the company says its own. */
    public const DEFAULT_EFFICIENCY = '80.00';

    /** The catalogue item a fabric leaves when the material does not say otherwise. */
    public const CUTTING_SCRAPS = 'cutting_scraps';

    /** How a waste line is estimated. */
    public const METHODS = ['cutting', 'loss_rate', 'per_unit', 'whole'];

    /** @var array<int, int|null> company id => its cutting scraps type */
    private array $scraps = [];

    /** @var array<int, array{ulid: string, name: string}|null> */
    private array $types = [];

    /** @var array<int, list<array{type: int, method: string, pct: string|null, rate: string|null, per_unit: string|null}>> raw material id => the waste it says it leaves */
    private array $shares = [];

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
     * The waste lines each raw material says it leaves, in their order; [] when it says nothing.
     *
     * @param  list<int>  $materialIds
     * @return array<int, list<array{type: int, method: string, pct: string|null, rate: string|null, per_unit: string|null}>>
     */
    public function shares(array $materialIds): array
    {
        $missing = array_values(array_diff($materialIds, array_keys($this->shares)));
        if ($missing !== []) {
            foreach ($missing as $id) {
                $this->shares[$id] = [];
            }
            DB::table('raw_material_waste_types')->whereIn('raw_material_id', $missing)->orderBy('position')->orderBy('id')
                ->get(['raw_material_id', 'waste_type_id', 'method', 'share_pct', 'rate_pct', 'kg_per_unit'])
                ->each(function ($row) {
                    $this->shares[(int) $row->raw_material_id][] = ['type' => (int) $row->waste_type_id, 'method' => (string) $row->method,
                        'pct' => $row->share_pct === null ? null : (string) $row->share_pct, 'rate' => $row->rate_pct === null ? null : (string) $row->rate_pct,
                        'per_unit' => $row->kg_per_unit === null ? null : (string) $row->kg_per_unit];
                });
        }

        return array_intersect_key($this->shares, array_flip($materialIds));
    }

    /**
     * The waste a raw material leaves, as it applies: its own lines, or for a fabric all the company's cutting scraps
     * (inherited). A type 0 is "nobody said, and the company has no cutting scraps type".
     *
     * @return array{lines: list<array{type: int, method: string, pct: string|null, rate: string|null, per_unit: string|null}>, inherited: bool}
     */
    public function applied(int $materialId, bool $fabric): array
    {
        $own = $this->shares([$materialId])[$materialId];
        if ($own !== [] || ! $fabric) {
            return ['lines' => $own, 'inherited' => false];
        }

        return ['lines' => [self::cuttingLine($this->scrapsType() ?? 0)], 'inherited' => true];
    }

    /** @return array{type: int, method: string, pct: string, rate: null, per_unit: null} */
    public static function cuttingLine(int $type, string $pct = '100.00'): array
    {
        return ['type' => $type, 'method' => 'cutting', 'pct' => $pct, 'rate' => null, 'per_unit' => null];
    }

    /**
     * Some kilograms of cutting waste shared between the cutting lines by their shares; the other lines take none of it.
     *
     * @param  list<array{type: int, method?: string, pct: string|null}>  $lines
     * @return array<int, string> type id => kilograms
     */
    public static function split(string $kg, array $lines): array
    {
        $out = [];
        foreach ($lines as $line) {
            if (($line['method'] ?? 'cutting') !== 'cutting' || $line['pct'] === null) {
                continue;
            }
            $out[$line['type']] = bcadd($out[$line['type']] ?? '0', bcdiv(bcmul($kg, $line['pct'], 10), '100', 8), 8);
        }

        return $out;
    }

    /**
     * Says the waste lines a raw material leaves, in this order; [] says nothing.
     *
     * @param  list<array{type: int, method: string, pct: string|null, rate: string|null, per_unit: string|null}>  $lines
     */
    public function save(int $companyId, int $materialId, array $lines): void
    {
        DB::table('raw_material_waste_types')->where('raw_material_id', $materialId)->delete();
        DB::table('raw_material_waste_types')->insert(array_map(fn (array $line, int $position) => ['company_id' => $companyId, 'raw_material_id' => $materialId,
            'waste_type_id' => $line['type'], 'method' => $line['method'], 'share_pct' => $line['pct'], 'rate_pct' => $line['rate'], 'kg_per_unit' => $line['per_unit'],
            'position' => $position, 'created_at' => now(), 'updated_at' => now()], $lines, array_keys($lines)));
        unset($this->shares[$materialId]);
    }

    /**
     * What a quantity of a raw material weighs: metres with laize and grammage, kilograms as they are, pieces with the
     * weight of one piece; null when it cannot be known.
     */
    public static function kilograms(string $quantity, ?string $unit, mixed $widthCm, mixed $grammageGsm, mixed $unitWeightKg): ?string
    {
        return match (true) {
            $unit === 'm' && $widthCm !== null && $grammageGsm !== null => bcdiv(bcmul(bcmul($quantity, (string) $widthCm, 8), (string) $grammageGsm, 8), '100000', 8),
            $unit === 'kg' => bcadd($quantity, '0', 8),
            $unit !== 'm' && $unitWeightKg !== null => bcmul($quantity, (string) $unitWeightKg, 8),
            default => null,
        };
    }

    /**
     * One line of a composition, per piece: the material consumed (in kg when it can be said), and each waste it leaves
     * by its own calculation. A waste line that cannot be computed gives null. `inherited`: the material says nothing,
     * a fabric is assumed to leave all the company's cutting scraps.
     *
     * @return array{efficiency_pct: string|null, efficiency_applied: string|null, fabric_kg: string|null, consumed_kg: string|null, cutting_kg: string|null,
     *               waste_kg: string|null, lines: list<array<string, mixed>>, by_type: array<int, string>, cutting_by_type: array<int, string>, other_by_type: array<int, string>, inherited: bool}
     */
    public function line(string $quantity, ?string $unit, mixed $widthCm, mixed $grammageGsm, mixed $efficiency, string $default, ?int $materialId = null, mixed $unitWeightKg = null): array
    {
        $own = $efficiency === null ? null : bcadd((string) $efficiency, '0', 2);
        $fabric = $unit === 'm' && $widthCm !== null && $grammageGsm !== null;
        $consumed = self::kilograms($quantity, $unit, $widthCm, $grammageGsm, $unitWeightKg);
        $applied = $fabric ? ($own ?? $default) : null;
        $cutting = $fabric ? bcdiv(bcmul((string) $consumed, bcsub('100', (string) $applied, 2), 10), '100', 8) : null;
        $types = $materialId === null ? ['lines' => $fabric ? [self::cuttingLine($this->scrapsType() ?? 0)] : [], 'inherited' => $fabric] : $this->applied($materialId, $fabric);

        $lines = [];
        $all = $cut = $other = [];
        foreach ($types['lines'] as $line) {
            $kg = match ($line['method']) {
                'cutting' => $cutting === null || $line['pct'] === null ? null : bcdiv(bcmul($cutting, $line['pct'], 10), '100', 8),
                'loss_rate' => $consumed === null || $line['rate'] === null ? null : bcdiv(bcmul($consumed, $line['rate'], 10), '100', 8),
                'per_unit' => $line['per_unit'] === null ? null : bcmul($quantity, $line['per_unit'], 8),
                'whole' => $consumed,
                default => null,
            };
            $lines[] = $line + ['kg' => $kg];
            if ($kg !== null) {
                $all[$line['type']] = bcadd($all[$line['type']] ?? '0', $kg, 8);
                if ($line['method'] === 'cutting') {
                    $cut[$line['type']] = bcadd($cut[$line['type']] ?? '0', $kg, 8);
                } else {
                    $other[$line['type']] = bcadd($other[$line['type']] ?? '0', $kg, 8);
                }
            }
        }

        return ['efficiency_pct' => $own, 'efficiency_applied' => $applied, 'fabric_kg' => $fabric ? $consumed : null, 'consumed_kg' => $consumed, 'cutting_kg' => $cutting,
            'waste_kg' => $all === [] ? null : self::total($all), 'lines' => $lines, 'by_type' => $all, 'cutting_by_type' => $cut, 'other_by_type' => $other, 'inherited' => $types['inherited']];
    }

    /** @param  array<int|string, string>  $kilograms */
    public static function total(array $kilograms): string
    {
        return array_reduce($kilograms, fn (string $sum, string $kg) => bcadd($sum, $kg, 8), '0');
    }

    /**
     * Adds kilograms by type into others.
     *
     * @param  array<int, string>  $into
     * @param  array<int, string>  $from
     * @return array<int, string>
     */
    public static function merge(array $into, array $from): array
    {
        foreach ($from as $type => $kg) {
            $into[$type] = bcadd($into[$type] ?? '0', $kg, 8);
        }

        return $into;
    }

    /**
     * The expected waste of one piece of each article, in kilograms: in all, by type, and material by material with the
     * cutting part apart from the rest (an OF with spreads replaces the cutting part of the fabrics it spread). An
     * article none of whose lines can be computed is absent.
     *
     * @param  list<int>  $articleIds
     * @return array<int, array{fabric_kg: string, cutting_kg: string, other_kg: string, waste_kg: string, by_type: array<int, string>, materials: array<int, array{cutting: array<int, string>, other: array<int, string>}>}>
     */
    public function perPiece(array $articleIds): array
    {
        if ($articleIds === []) {
            return [];
        }
        $default = $this->defaultEfficiency();
        $rows = DB::table('article_materials as am')->join('raw_materials as m', 'm.id', '=', 'am.raw_material_id')->join('units as u', 'u.id', '=', 'm.unit_id')
            ->whereIn('am.article_id', $articleIds)
            ->get(['am.article_id', 'am.raw_material_id', 'am.quantity_per_piece', 'am.efficiency_pct', 'u.code as unit', 'm.width_cm', 'm.grammage_gsm', 'm.unit_weight_kg']);
        $this->shares($rows->pluck('raw_material_id')->map(fn ($id) => (int) $id)->unique()->values()->all());
        $out = [];
        foreach ($rows as $row) {
            $line = $this->line((string) $row->quantity_per_piece, $row->unit, $row->width_cm, $row->grammage_gsm, $row->efficiency_pct, $default, (int) $row->raw_material_id, $row->unit_weight_kg);
            if ($line['waste_kg'] === null) {
                continue;
            }
            $sum = $out[$row->article_id] ?? ['fabric_kg' => '0', 'cutting_kg' => '0', 'other_kg' => '0', 'waste_kg' => '0', 'by_type' => [], 'materials' => []];
            $sum['fabric_kg'] = bcadd($sum['fabric_kg'], $line['fabric_kg'] ?? '0', 8);
            $sum['cutting_kg'] = bcadd($sum['cutting_kg'], self::total($line['cutting_by_type']), 8);
            $sum['other_kg'] = bcadd($sum['other_kg'], self::total($line['other_by_type']), 8);
            $sum['waste_kg'] = bcadd($sum['waste_kg'], $line['waste_kg'], 8);
            $sum['by_type'] = self::merge($sum['by_type'], $line['by_type']);
            $material = $sum['materials'][(int) $row->raw_material_id] ?? ['cutting' => [], 'other' => []];
            $sum['materials'][(int) $row->raw_material_id] = ['cutting' => self::merge($material['cutting'], $line['cutting_by_type']), 'other' => self::merge($material['other'], $line['other_by_type'])];
            $out[$row->article_id] = $sum;
        }

        return $out;
    }
}
