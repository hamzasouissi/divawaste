<?php

namespace App\Modules\Sites\Actions;

use App\Modules\Common\Support\Constraint;
use App\Modules\Notifications\Actions\NotifyUsers;
use App\Modules\Sites\Enums\AlertLevel;
use App\Modules\Sites\Models\StockThreshold;
use App\Modules\Sites\Support\StockQuery;
use Illuminate\Support\Facades\DB;

/**
 * M2-07: after every stock movement on a site, each active threshold of that site is compared with the real stock.
 *
 * A limit is in one of four states: nothing to say, warning, exceeded — both on the weight that is known — or
 * unverifiable: the known weight is below the limit but lots of its scope were never weighed, so nobody can say the
 * limit is respected. People are told when the state changes for the worse, not on every movement while it stays the
 * same; a stock limit alerts and never refuses a lot.
 */
final class EvaluateStockThresholds
{
    /** How many lot numbers a message names; the count is always complete. */
    private const NAMED = 10;

    public function __construct(private readonly StockQuery $stock, private readonly NotifyUsers $notify) {}

    public function forSite(int $siteId): void
    {
        $thresholds = StockThreshold::query()->where('site_id', $siteId)->where('is_active', true)->get();

        foreach ($thresholds as $threshold) {
            $quantity = $this->stock->quantity($siteId, $threshold->zone_id, $threshold->waste_type_id);
            $unweighed = $this->stock->unweighedNumbers($siteId, $threshold->zone_id, $threshold->waste_type_id);
            $level = self::level($quantity, (string) $threshold->max_quantity_kg, $threshold->warning_pct, count($unweighed));
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
                $this->notify->toSitePermission($siteId, 'stock.view', 'stock.threshold_'.$level->value, [
                    'site' => $site, 'zone' => $zone, 'waste_type' => $type,
                    // What the limit watches, in one piece: "Usine de Nabeul · Atelier de coupe · Chutes de coupe".
                    'scope' => implode(' · ', array_filter([$site, $zone, $type])),
                    'quantity_kg' => $quantity,
                    'max_quantity_kg' => (string) $threshold->max_quantity_kg,
                    // The lots the known weight does not include: how many, and which (the first ones when there are many).
                    'unweighed_lots' => count($unweighed),
                    'lots' => implode(', ', array_slice($unweighed, 0, self::NAMED)).(count($unweighed) > self::NAMED ? '…' : ''),
                ], '/stock');
            }
        }
    }

    /**
     * The state of a limit, answered like any other limit: exceeded on what is known wins; below it, lots nobody weighed
     * make it unverifiable; otherwise the warning percentage decides.
     */
    public static function level(string $quantity, string $max, int $warningPct, int $unweighed = 0): ?AlertLevel
    {
        $outcome = Constraint::quantity('stock', 'kg', $quantity, $max, $warningPct, $unweighed > 0 ? ['?'] : [])['outcome'];

        return $outcome === Constraint::RESPECTED ? null : AlertLevel::from($outcome);
    }

    /**
     * Whether a change of state is news. It is when things get worse (nothing → warning → unverifiable → exceeded), and
     * when an unverifiable limit turns out to be at its warning once the lots are weighed. Falling back from exceeded
     * is not announced, and the same state is never announced twice in a row.
     */
    private static function tells(?AlertLevel $previous, AlertLevel $level): bool
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
