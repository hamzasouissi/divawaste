<?php

namespace App\Modules\Sites\Support;

use App\Modules\Common\Support\Constraint;
use App\Modules\Common\Support\ReferenceCodes;
use App\Modules\Sites\Models\Zone;
use Illuminate\Support\Facades\DB;

/**
 * What a zone is meant to hold (optional waste types and colours), how much of it, and how full it is right now.
 * A zone has two separate physical limits: the room it offers (packages per packaging, sharing one room) and the load
 * it bears (kilograms). Each is answered on its own; neither percentage stands for the other. Like the stock, the fill is never stored: it is counted from the lots on site.
 * Registered as a scoped service: what it reads is kept for one request, never longer.
 */
final class ZoneOrganisation
{
    /** @var array<int, array{capacities: list<array{packaging_type: string, max_count: int}>, accepted_waste_types: list<string>, accepted_colors: list<string>, waste_type_ids: list<int>, color_ids: list<int>}> */
    private array $rules = [];

    /** @var array<int, array<int, array{kg: string, lots: int, counts: array<string, int>, misplaced: int, unweighed: int, unconfirmed: int}>> */
    private array $usage = [];

    /** @var array<int, array<int, array{unweighed: list<string>, uncounted: list<string>}>> */
    private array $named = [];

    public function __construct(private readonly StockQuery $stock, private readonly ReferenceCodes $codes) {}

    /**
     * @return array{capacities: list<array{packaging_type: string, max_count: int}>, accepted_waste_types: list<string>, accepted_colors: list<string>, waste_type_ids: list<int>, color_ids: list<int>}
     */
    public function of(int $zoneId): array
    {
        $this->load([$zoneId]);

        return $this->rules[$zoneId];
    }

    /**
     * @param  list<int>  $zoneIds
     */
    public function load(array $zoneIds): void
    {
        $missing = array_values(array_diff($zoneIds, array_keys($this->rules)));
        if ($missing === []) {
            return;
        }
        foreach ($missing as $id) {
            $this->rules[$id] = ['capacities' => [], 'accepted_waste_types' => [], 'accepted_colors' => [], 'waste_type_ids' => [], 'color_ids' => []];
        }

        foreach (DB::table('zone_capacities')->whereIn('zone_id', $missing)->orderBy('id')->get(['zone_id', 'packaging_type_id', 'max_count']) as $row) {
            $this->rules[$row->zone_id]['capacities'][] = [
                'packaging_type' => (string) $this->codes->code('packaging_types', (int) $row->packaging_type_id), 'max_count' => (int) $row->max_count,
            ];
        }
        foreach (DB::table('zone_accepted_waste_types as a')->join('waste_types as t', 't.id', '=', 'a.waste_type_id')
            ->whereIn('a.zone_id', $missing)->orderBy('t.name')->get(['a.zone_id', 't.id', 't.ulid']) as $row) {
            $this->rules[$row->zone_id]['accepted_waste_types'][] = (string) $row->ulid;
            $this->rules[$row->zone_id]['waste_type_ids'][] = (int) $row->id;
        }
        foreach (DB::table('zone_accepted_colors as a')->join('color_families as c', 'c.id', '=', 'a.color_family_id')
            ->whereIn('a.zone_id', $missing)->orderBy('c.sort_order')->orderBy('c.id')->get(['a.zone_id', 'c.id', 'c.code']) as $row) {
            $this->rules[$row->zone_id]['accepted_colors'][] = (string) $row->code;
            $this->rules[$row->zone_id]['color_ids'][] = (int) $row->id;
        }
    }

    /**
     * Replaces the lists that are present in the input; a list left out is not touched.
     *
     * @param  array<string, mixed>  $input
     */
    public function sync(Zone $zone, array $input): void
    {
        $base = ['company_id' => $zone->company_id, 'zone_id' => $zone->id, 'created_at' => now(), 'updated_at' => now()];

        DB::transaction(function () use ($zone, $input, $base) {
            if (array_key_exists('capacities', $input)) {
                DB::table('zone_capacities')->where('zone_id', $zone->id)->delete();
                DB::table('zone_capacities')->insert(array_map(fn (array $line) => $base + [
                    'packaging_type_id' => $this->codes->id('packaging_types', $line['packaging_type']), 'max_count' => (int) $line['max_count'],
                ], $input['capacities'] ?? []));
            }
            if (array_key_exists('accepted_waste_types', $input)) {
                DB::table('zone_accepted_waste_types')->where('zone_id', $zone->id)->delete();
                $ids = DB::table('waste_types')->where('company_id', $zone->company_id)
                    ->whereIn('ulid', array_map(strtoupper(...), $input['accepted_waste_types'] ?? []))->pluck('id');
                DB::table('zone_accepted_waste_types')->insert($ids->map(fn ($id) => $base + ['waste_type_id' => $id])->all());
            }
            if (array_key_exists('accepted_colors', $input)) {
                DB::table('zone_accepted_colors')->where('zone_id', $zone->id)->delete();
                $ids = DB::table('color_families')->whereIn('code', $input['accepted_colors'] ?? [])
                    ->where(fn ($query) => $query->whereNull('company_id')->orWhere('company_id', $zone->company_id))->pluck('id');
                DB::table('zone_accepted_colors')->insert($ids->map(fn ($id) => $base + ['color_family_id' => $id])->all());
            }
        });

        unset($this->rules[$zone->id]);
    }

    /**
     * How full the zone is. `constraints` holds one answer per limit the zone states:
     * - 'space': packages against the room ("100 bags or 50 boxes" is one room, shared). Loose lots and packagings the
     *   room does not plan for cannot be counted: they are named, and the occupancy is not said to be verified;
     * - 'load': known kilograms against the weight the zone bears. Lots nobody weighed are named, never counted as zero.
     * Nothing here blocks a placement: the zone informs and alerts.
     *
     * @return array<string, mixed>
     */
    public function fill(int $siteId, int $zoneId, ?string $capacityKg, int $alertPct): array
    {
        $usage = $this->usage($siteId)[$zoneId] ?? ['kg' => '0.000', 'lots' => 0, 'counts' => [], 'misplaced' => 0, 'unweighed' => 0, 'unconfirmed' => 0];
        $room = array_filter(array_column($this->of($zoneId)['capacities'], 'max_count', 'packaging_type'), fn (int $max) => $max > 0);
        $hasLoad = $capacityKg !== null && bccomp($capacityKg, '0', 3) > 0;
        $named = ($room !== [] && array_diff_key($usage['counts'], $room) !== []) || ($hasLoad && $usage['unweighed'] > 0)
            ? ($this->named($siteId)[$zoneId] ?? ['unweighed' => [], 'uncounted' => []]) : ['unweighed' => [], 'uncounted' => []];

        $constraints = [];
        if ($room !== []) {
            $constraints[] = Constraint::packages('space', $room, $usage['counts'], $alertPct, $named['uncounted']);
        }
        if ($hasLoad) {
            $constraints[] = Constraint::quantity('load', 'kg', $usage['kg'], $capacityKg, $alertPct, $named['unweighed']);
        }
        $overall = Constraint::overall($constraints);

        return [
            'constraints' => $constraints,
            // The most serious answer among the limits, for a badge; the figures stay per limit.
            'outcome' => $overall['outcome'],
            'alert_level' => in_array($overall['outcome'], [Constraint::EXCEEDED, Constraint::WARNING], true) ? $overall['outcome'] : null,
            'quantity_kg' => $usage['kg'],
            'lots' => $usage['lots'],
            // Lots in the zone nobody weighed: in the number of lots, not in the weight.
            'unweighed_lots' => $usage['unweighed'],
            // Packages per packaging ('' = loose lots, counted as lots: they have no packages), and how many lots only carry
            // the default of one package nobody confirmed.
            'packages' => $usage['counts'],
            'unconfirmed_lots' => $usage['unconfirmed'],
            'misplaced' => $usage['misplaced'],
        ];
    }

    /**
     * The lots a limit of their zone cannot count, by number: unweighed ones, and those loose or in a packaging the room
     * of the zone does not plan for.
     *
     * @return array<int, array{unweighed: list<string>, uncounted: list<string>}>
     */
    private function named(int $siteId): array
    {
        if (isset($this->named[$siteId])) {
            return $this->named[$siteId];
        }
        $rows = $this->stock->lots([$siteId])
            ->where(fn ($query) => $query->whereNull('l.net_weight_kg')->orWhere(fn ($query) => $query
                ->whereExists(fn ($sub) => $sub->from('zone_capacities as c')->whereColumn('c.zone_id', 'l.zone_id')->where('c.max_count', '>', 0))
                ->where(fn ($query) => $query->whereNull('l.packaging_type_id')->orWhereNotExists(fn ($sub) => $sub->from('zone_capacities as c')
                    ->whereColumn('c.zone_id', 'l.zone_id')->whereColumn('c.packaging_type_id', 'l.packaging_type_id')->where('c.max_count', '>', 0)))))
            ->orderBy('l.id')->get(['l.zone_id', 'l.lot_number', 'l.packaging_type_id', DB::raw('l.net_weight_kg IS NULL as unweighed')]);
        $this->load($rows->pluck('zone_id')->unique()->map(fn ($id) => (int) $id)->values()->all());

        $named = [];
        foreach ($rows as $row) {
            $zone = &$named[(int) $row->zone_id];
            $zone ??= ['unweighed' => [], 'uncounted' => []];
            if ((bool) $row->unweighed) {
                $zone['unweighed'][] = (string) $row->lot_number;
            }
            $room = array_filter(array_column($this->rules[(int) $row->zone_id]['capacities'], 'max_count', 'packaging_type'), fn (int $max) => $max > 0);
            $kind = (string) $this->codes->code('packaging_types', $row->packaging_type_id === null ? null : (int) $row->packaging_type_id);
            if ($room !== [] && ! isset($room[$kind])) {
                $zone['uncounted'][] = (string) $row->lot_number;
            }
            unset($zone);
        }

        return $this->named[$siteId] = $named;
    }

    /**
     * Lots on site, per zone: weight, number per packaging ('' when a lot has none) and lots the zone is not meant for.
     *
     * @return array<int, array{kg: string, lots: int, counts: array<string, int>, misplaced: int, unweighed: int, unconfirmed: int}>
     */
    private function usage(int $siteId): array
    {
        if (isset($this->usage[$siteId])) {
            return $this->usage[$siteId];
        }

        $rows = $this->stock->lots([$siteId])
            ->groupBy('l.zone_id', 'l.packaging_type_id', 'l.waste_type_id', 'l.color_family_id')
            ->get(['l.zone_id', 'l.packaging_type_id', 'l.waste_type_id', 'l.color_family_id', DB::raw('COUNT(*) as lots'), DB::raw('COALESCE(SUM(l.net_weight_kg), 0) as kg'), DB::raw('SUM(l.net_weight_kg IS NULL) as unweighed'),
                // A lot is as many packages as it says; a loose lot has none and is reported, not turned into one.
                DB::raw('SUM(CASE WHEN l.packaging_type_id IS NULL THEN 1 ELSE COALESCE(l.package_count, 1) END) as packages'),
                DB::raw('SUM(l.packaging_type_id IS NOT NULL AND l.package_count_confirmed = 0) as unconfirmed')]);
        $this->load($rows->pluck('zone_id')->unique()->map(fn ($id) => (int) $id)->values()->all());

        $usage = [];
        foreach ($rows as $row) {
            $zone = &$usage[(int) $row->zone_id];
            $zone ??= ['kg' => '0.000', 'lots' => 0, 'counts' => [], 'misplaced' => 0, 'unweighed' => 0, 'unconfirmed' => 0];
            $zone['unconfirmed'] += (int) $row->unconfirmed;
            $zone['unweighed'] += (int) $row->unweighed;
            $kind = (string) $this->codes->code('packaging_types', $row->packaging_type_id === null ? null : (int) $row->packaging_type_id);
            $zone['kg'] = bcadd($zone['kg'], (string) $row->kg, 3);
            $zone['lots'] += (int) $row->lots;
            $zone['counts'][$kind] = ($zone['counts'][$kind] ?? 0) + (int) $row->packages;
            if (! $this->fits($this->rules[(int) $row->zone_id], (int) $row->waste_type_id, $row->color_family_id === null ? null : (int) $row->color_family_id)) {
                $zone['misplaced'] += (int) $row->lots;
            }
            unset($zone);
        }

        return $this->usage[$siteId] = $usage;
    }

    /**
     * An empty list accepts anything. A lot whose colour is not stated is not called misplaced: nobody knows.
     *
     * @param  array{waste_type_ids: list<int>, color_ids: list<int>}  $rules
     */
    private function fits(array $rules, int $wasteTypeId, ?int $colorId): bool
    {
        return ($rules['waste_type_ids'] === [] || in_array($wasteTypeId, $rules['waste_type_ids'], true))
            && ($rules['color_ids'] === [] || $colorId === null || in_array($colorId, $rules['color_ids'], true));
    }
}
