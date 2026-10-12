<?php

namespace App\Modules\Sites\Support;

use App\Modules\Common\Support\Constraint;
use App\Modules\Common\Support\Measure;
use App\Modules\Common\Support\ReferenceCodes;
use App\Modules\Sites\Models\StockThreshold;

/**
 * A stock limit answered in the unit it is stated in: the lots of its scope (site, optional zone, optional waste type)
 * are counted in that unit, and those that cannot be are named. A limit in packages counts the packages of the packaging
 * it names; the other lots of the scope are outside it and are said to be. A stock limit alerts, it refuses nothing.
 */
final class StockLimits
{
    /** How many lot numbers an answer names; the count is always complete. */
    private const NAMED = 50;

    public function __construct(private readonly StockQuery $stock, private readonly ReferenceCodes $codes) {}

    /**
     * @return array<string, mixed>
     */
    public function constraint(StockThreshold $threshold): array
    {
        $unit = (string) ($threshold->unit_code ?? 'kg');
        $packaging = $this->codes->code('packaging_types', $threshold->packaging_type_id);
        $scale = Measure::scale($unit);
        $total = Measure::total($this->stock->measures((int) $threshold->site_id, $threshold->zone_id, $threshold->waste_type_id), $unit, $packaging);

        return Constraint::quantity('stock', $unit, $total['used'], bcadd((string) $threshold->max_quantity, '0', $scale), (int) $threshold->warning_pct, $total['missing'], false, $scale) + [
            'packaging_type' => $packaging,
            // Lots of the scope this limit does not concern: another packaging, or loose waste, under a limit in packages.
            'outside_count' => count($total['outside']),
            'outside_lots' => array_slice($total['outside'], 0, self::NAMED),
        ];
    }

    /** What the limit measures: one limit per scope for each of these. */
    public static function measureKey(string $unit, ?int $packagingTypeId): string
    {
        return $unit === Measure::PACKAGE ? 'package:'.(int) $packagingTypeId : (string) Measure::dimension($unit);
    }
}
