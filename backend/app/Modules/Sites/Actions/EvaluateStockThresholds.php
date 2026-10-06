<?php

namespace App\Modules\Sites\Actions;

use App\Modules\Notifications\Actions\NotifyUsers;
use App\Modules\Sites\Enums\AlertLevel;
use App\Modules\Sites\Models\StockThreshold;
use App\Modules\Sites\Support\StockQuery;
use Illuminate\Support\Facades\DB;

/**
 * M2-07: after every stock movement on a site, each active threshold of that site is compared with the real stock.
 * People are alerted when the level rises (none → warning → exceeded), not on every movement while it stays high.
 */
final class EvaluateStockThresholds
{
    public function __construct(private readonly StockQuery $stock, private readonly NotifyUsers $notify) {}

    public function forSite(int $siteId): void
    {
        $thresholds = StockThreshold::query()->where('site_id', $siteId)->where('is_active', true)->get();

        foreach ($thresholds as $threshold) {
            $quantity = $this->stock->quantity($siteId, $threshold->zone_id, $threshold->waste_type_id);
            $level = self::level($quantity, (string) $threshold->max_quantity_kg, $threshold->warning_pct);
            $previous = $threshold->alert_level;

            // Direct update: evaluation bookkeeping is not a business change worth an audit row.
            DB::table('stock_thresholds')->where('id', $threshold->id)->update([
                'alert_level' => $level?->value,
                'alerted_at' => $level === null ? null : ($level === $previous ? $threshold->alerted_at : now()),
                'last_evaluated_at' => now(),
            ]);

            if ($level !== null && self::rank($level) > self::rank($previous)) {
                $this->notify->toSitePermission($siteId, 'stock.view', 'stock.threshold_'.$level->value, [
                    'site' => DB::table('sites')->where('id', $siteId)->value('name'),
                    'zone' => $threshold->zone_id === null ? null : DB::table('zones')->where('id', $threshold->zone_id)->value('name'),
                    'waste_type' => $threshold->waste_type_id === null ? null : DB::table('waste_types')->where('id', $threshold->waste_type_id)->value('name'),
                    'quantity_kg' => $quantity,
                    'max_quantity_kg' => (string) $threshold->max_quantity_kg,
                ], '/stock');
            }
        }
    }

    public static function level(string $quantity, string $max, int $warningPct): ?AlertLevel
    {
        if (bccomp($quantity, $max, 3) > 0) {
            return AlertLevel::Exceeded;
        }

        return bccomp($quantity, bcdiv(bcmul($max, (string) $warningPct, 3), '100', 3), 3) >= 0 ? AlertLevel::Warning : null;
    }

    private static function rank(?AlertLevel $level): int
    {
        return match ($level) {
            null => 0,
            AlertLevel::Warning => 1,
            AlertLevel::Exceeded => 2,
        };
    }
}
