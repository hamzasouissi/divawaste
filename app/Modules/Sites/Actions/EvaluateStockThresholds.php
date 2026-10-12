<?php

namespace App\Modules\Sites\Actions;

use App\Modules\Common\Support\Constraint;
use App\Modules\Common\Support\QuantityText;
use App\Modules\Notifications\Actions\NotifyUsers;
use App\Modules\Sites\Enums\AlertLevel;
use App\Modules\Sites\Models\StockThreshold;
use App\Modules\Sites\Support\StockLimits;
use Illuminate\Support\Facades\DB;

/**
 * M2-07: after every stock movement on a site, each active limit of that site is compared with the real stock, in the
 * unit the limit is stated in.
 *
 * A limit is in one of four states: nothing to say, warning, exceeded — both on the quantity that is known — or
 * unverifiable: the known quantity is below the limit but lots of its scope could not be counted in its unit, so nobody
 * can say the limit is respected. People are told when the state changes for the worse, not on every movement while it
 * stays the same; a stock limit alerts and never refuses a lot.
 */
final class EvaluateStockThresholds
{
    /** How many lot numbers a message names; the count is always complete. */
    private const NAMED = 10;

    public function __construct(private readonly StockLimits $limits, private readonly NotifyUsers $notify, private readonly EvaluateCapacityAlerts $capacity) {}

    public function forSite(int $siteId): void
    {
        // The same movement may fill a zone or the site: their physical limits are looked at too.
        $this->capacity->forSite($siteId);

        $thresholds = StockThreshold::query()->where('site_id', $siteId)->where('is_active', true)->orderBy('id')->get();

        foreach ($thresholds as $threshold) {
            $limit = $this->limits->constraint($threshold);
            $level = $limit['outcome'] === Constraint::RESPECTED ? null : AlertLevel::from($limit['outcome']);
            $previous = $threshold->alert_level;

            // Direct update: evaluation bookkeeping is not a business change worth an audit row.
            DB::table('stock_thresholds')->where('id', $threshold->id)->update([
                'alert_level' => $level?->value,
                'alerted_at' => $level === null ? null : ($level === $previous ? $threshold->alerted_at : now()),
                'last_evaluated_at' => now(),
            ]);

            if ($level !== null && self::tells($previous, $level)) {
                $site = (string) DB::table('sites')->where('id', $siteId)->value('name');
                $zone = $threshold->zone_id === null ? null : (string) DB::table('zones')->where('id', $threshold->zone_id)->value('name');
                $type = $threshold->waste_type_id === null ? null : (string) DB::table('waste_types')->where('id', $threshold->waste_type_id)->value('name');
                $lots = array_slice($limit['missing_lots'], 0, self::NAMED);
                $amount = fn (mixed $value) => fn (string $locale) => $value === null ? null : QuantityText::amount($value, (string) $limit['unit'], $limit['packaging_type'], $locale);
                $this->notify->toSitePermission($siteId, 'stock.view', 'stock.threshold_'.$level->value, [
                    'site' => $site, 'zone' => $zone, 'waste_type' => $type,
                    // What the limit watches, in one piece: "Usine de Nabeul · Atelier de coupe · Chutes de coupe".
                    'scope' => implode(' · ', array_filter([$site, $zone, $type])),
                    // The lots the known quantity does not include: how many, and which (the first ones when there are many).
                    'unweighed_lots' => (int) $limit['missing_count'],
                    'lots' => implode(', ', $lots).($limit['missing_count'] > count($lots) ? '…' : ''),
                    // The figures with their unit, written for each language: "320 Sac", "0,9 t" / "0.9 t".
                ] + QuantityText::localized('quantity', $amount($limit['used'])) + QuantityText::localized('max_quantity', $amount($limit['max']))
                    + QuantityText::localized('excess', $amount($limit['excess'])), '/stock');
            }
        }
    }

    /**
     * Whether a change of state is news. It is when things get worse (nothing → warning → unverifiable → exceeded), and
     * when an unverifiable limit turns out to be at its warning once the lots are counted. Falling back from exceeded
     * is not announced, and the same state is never announced twice in a row.
     */
    public static function tells(?AlertLevel $previous, AlertLevel $level): bool
    {
        return self::rank($level) > self::rank($previous) || ($previous === AlertLevel::Unverifiable && $level === AlertLevel::Warning);
    }

    private static function rank(?AlertLevel $level): int
    {
        return match ($level) {
            null => 0,
            AlertLevel::Warning => 1,
            AlertLevel::Unverifiable => 2,
            AlertLevel::Exceeded => 3,
        };
    }
}
