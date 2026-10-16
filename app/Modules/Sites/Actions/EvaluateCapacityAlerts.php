<?php

namespace App\Modules\Sites\Actions;

use App\Modules\Common\Support\Constraint;
use App\Modules\Common\Support\QuantityText;
use App\Modules\Notifications\Actions\NotifyUsers;
use App\Modules\Sites\Enums\AlertLevel;
use App\Modules\Sites\Models\Site;
use App\Modules\Sites\Models\Zone;
use App\Modules\Sites\Support\ZoneOrganisation;
use Illuminate\Support\Facades\DB;

/**
 * Tells people when what a zone can physically hold becomes a concern: its room and its load (a limit on the whole
 * site is a stock limit, evaluated with the others). Same rule as the stock limits: a message when the state gets worse (nothing → warning →
 * unverifiable → exceeded), or when an unverifiable limit turns out to be at its warning; never twice for the same
 * state, never when it falls back. "Exceeded" is a fact on what is known; "unverifiable" is an incomplete measure, and
 * the two are different messages. Nothing here refuses a lot.
 */
final class EvaluateCapacityAlerts
{
    /** How many lot numbers a message names; the count is always complete. */
    private const NAMED = 10;

    public function __construct(private readonly ZoneOrganisation $organisation, private readonly NotifyUsers $notify) {}

    public function forSite(int $siteId): void
    {
        $site = Site::query()->find($siteId);
        if ($site === null) {
            return;
        }
        // Counted again from the lots as they are now, not from what this request read before the movement.
        $this->organisation->forget();

        /** @var array<string, array{zone: Zone|null, limit: array<string, mixed>}> $limits */
        $limits = [];
        foreach (Zone::query()->where('site_id', $siteId)->where('is_active', true)->orderBy('id')->get() as $zone) {
            $fill = $this->organisation->fill($siteId, $zone->id, $zone->capacity_kg, $zone->capacity_alert_pct, $zone->capacity_m3, $zone->space_method ?? 'packaging');
            foreach ($fill['constraints'] as $limit) {
                $limits[$zone->id.'|'.$limit['key']] = ['zone' => $zone, 'limit' => $limit];
            }
        }

        $stored = DB::table('capacity_alerts')->where('site_id', $siteId)->get()->keyBy(fn ($row) => $row->zone_id.'|'.$row->capacity_type);
        foreach ($limits as $key => ['zone' => $zone, 'limit' => $limit]) {
            $level = $limit['outcome'] === Constraint::RESPECTED ? null : AlertLevel::from($limit['outcome']);
            $row = $stored->pull($key);
            $previous = $row === null ? null : AlertLevel::tryFrom((string) $row->alert_level);
            $state = ['alert_level' => $level?->value, 'alerted_at' => $level === null ? null : ($level === $previous ? $row->alerted_at : now()), 'last_evaluated_at' => now(), 'updated_at' => now()];
            $row === null
                ? DB::table('capacity_alerts')->insert($state + ['company_id' => $site->company_id, 'site_id' => $siteId, 'zone_id' => $zone?->id, 'capacity_type' => $limit['key'], 'created_at' => now()])
                : DB::table('capacity_alerts')->where('id', $row->id)->update($state);

            if ($level !== null && EvaluateStockThresholds::tells($previous, $level)) {
                $this->notify->toSitePermission($siteId, 'stock.view', 'capacity.'.$limit['key'].'_'.$level->value, $this->describe($site, $zone, $limit), '/stock');
            }
        }
        // A limit that no longer exists (removed, or its zone closed) has no state to remember.
        DB::table('capacity_alerts')->whereIn('id', $stored->pluck('id'))->delete();
    }

    /** Every site of a company: after a change that concerns them all, such as the volume of a packaging. */
    public function forCompany(int $companyId): void
    {
        foreach (DB::table('sites')->where('company_id', $companyId)->where('is_active', true)->pluck('id') as $siteId) {
            $this->forSite((int) $siteId);
        }
    }

    /**
     * What the message says: where, which limit, what is known against it, and the lots that could not be counted.
     *
     * @param  array<string, mixed>  $limit
     * @return array<string, scalar|null>
     */
    private function describe(Site $site, ?Zone $zone, array $limit): array
    {
        $lots = array_slice($limit['missing_lots'], 0, self::NAMED);

        return [
            'site' => $site->name, 'zone' => $zone?->name,
            'scope' => implode(' · ', array_filter([$site->name, $zone?->name])),
            'pct' => $limit['pct'] === null ? null : (string) round((float) $limit['pct']),
            'missing' => (int) $limit['missing_count'],
            'lots' => $lots === [] ? null : implode(', ', $lots).($limit['missing_count'] > count($lots) ? '…' : ''),
            // The figures of the limit in its own unit, written for each language: "17 m³ sur 20 m³" / "17 m³ of 20 m³",
            // "50 sur 100 Sac + 25 sur 50 Carton" / "50 of 100 Bag + 25 of 50 Carton".
        ] + QuantityText::localized('detail', fn (string $locale) => isset($limit['lines'])
            ? implode(' + ', array_map(fn (array $line) => QuantityText::ratio($line['used'], $line['max'], 'package', $line['packaging_type'], $locale), $limit['lines']))
            : QuantityText::ratio($limit['used'], $limit['max'], (string) $limit['unit'], null, $locale))
            + QuantityText::localized('excess', fn (string $locale) => isset($limit['excess']) ? QuantityText::amount($limit['excess'], (string) $limit['unit'], null, $locale) : null);
    }
}
