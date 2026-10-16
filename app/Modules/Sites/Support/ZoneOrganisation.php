<?php

namespace App\Modules\Sites\Support;

use App\Modules\Catalog\Support\UnitConversion;
use App\Modules\Common\Support\Constraint;
use App\Modules\Common\Support\ReferenceCodes;
use App\Modules\Sites\Models\Zone;
use Illuminate\Support\Facades\DB;

/**
 * What a zone is meant to hold (optional waste types and colours), how much of it, and how full it is right now.
 * A zone has two separate physical limits: the room it offers and the load it bears (kilograms). Each is answered on
 * its own; neither percentage stands for the other. The room is measured one way per zone, never both: in packages per
 * packaging sharing one room ("100 bags or 50 boxes"), or in volume against the cubic metres of the zone. A site has
 * one more limit of its own: the weight it may hold in all, every lot counted once whatever its zone. Like the stock, the fill is never stored: it is counted from the lots on site.
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

    /** @var array<int, string>|null  packaging id => cubic metres one package takes, for the company */
    private ?array $packageVolumes = null;

    public function __construct(private readonly StockQuery $stock, private readonly ReferenceCodes $codes) {}

    /** Drops what was read: the next question is answered from the lots and the settings as they are now. */
    public function forget(): void
    {
        [$this->rules, $this->usage, $this->named, $this->packageVolumes] = [[], [], [], null];
    }

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
     * - 'space', by packages: packages against the room ("100 bags or 50 boxes" is one room, shared). Loose lots and
     *   packagings the room does not plan for cannot be counted: they are named, and the occupancy is not said to be verified;
     * - 'space', by volume: cubic metres the lots take against the volume of the zone. A lot whose volume nobody can
     *   state (a packaging without a volume, loose waste without a measured volume or a density) is named the same way;
     * - 'load': known kilograms against the weight the zone bears. Lots nobody weighed are named, never counted as zero.
     * Nothing here blocks a placement: the zone informs and alerts.
     *
     * @return array<string, mixed>
     */
    public function fill(int $siteId, int $zoneId, ?string $capacityKg, int $alertPct, ?string $capacityM3 = null, string $method = 'packaging'): array
    {
        $usage = $this->usage($siteId)[$zoneId] ?? ['kg' => '0.000', 'lots' => 0, 'counts' => [], 'misplaced' => 0, 'unweighed' => 0, 'unconfirmed' => 0];
        $room = array_filter(array_column($this->of($zoneId)['capacities'], 'max_count', 'packaging_type'), fn (int $max) => $max > 0);
        $hasLoad = $capacityKg !== null && bccomp($capacityKg, '0', 3) > 0;
        // One way of measuring the room, the one the zone states. Volume without a volume to compare with measures nothing.
        $byVolume = $method === 'volume' && $capacityM3 !== null && bccomp($capacityM3, '0', 3) > 0;
        $named = (! $byVolume && $room !== [] && array_diff_key($usage['counts'], $room) !== []) || ($hasLoad && $usage['unweighed'] > 0)
            ? ($this->named($siteId)[$zoneId] ?? ['unweighed' => [], 'uncounted' => []]) : ['unweighed' => [], 'uncounted' => []];

        $constraints = [];
        $volume = null;
        if ($byVolume) {
            $volume = $this->volume($siteId, $zoneId);
            $constraints[] = Constraint::quantity('space', 'm3', $volume['used'], (string) $capacityM3, $alertPct, $volume['missing']) + ['method' => 'volume'];
        } elseif ($room !== []) {
            $constraints[] = Constraint::packages('space', $room, $usage['counts'], $alertPct, $named['uncounted']) + ['method' => 'packaging'];
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
            // By volume only: what takes the room, per packaging ('' = loose waste), so the total can be checked by eye.
            'volume' => $volume === null ? null : ['used_m3' => $volume['used'], 'lines' => $volume['lines']],
        ];
    }

    /**
     * The weight a site holds. Every lot on the site is counted once, whatever its zone (not a sum of zones: a zone
     * without a limit still weighs on the site); lots nobody weighed are counted apart. Watching it is the work of a
     * stock limit on the whole site, in the unit chosen for it.
     *
     * @return array{quantity_kg: string, lots: int, unweighed_lots: int}
     */
    public function siteLoad(int $siteId): array
    {
        $zones = $this->usage($siteId);

        return ['quantity_kg' => array_reduce($zones, fn (string $sum, array $zone) => bcadd($sum, $zone['kg'], 3), '0.000'),
            'lots' => array_sum(array_column($zones, 'lots')), 'unweighed_lots' => array_sum(array_column($zones, 'unweighed'))];
    }

    /**
     * The room the lots of a zone take, in cubic metres. A packaged lot takes its packages times the volume the company
     * states for that packaging. Loose waste takes the volume it was measured in (litres, m³) or, failing that, its
     * weight divided by the density of its waste type. Anything else is unknown, named, and left out of the total.
     *
     * @return array{used: string, lines: list<array{packaging_type: string, packages: int|null, lots: int, volume_m3: string}>, missing: list<string>}
     */
    private function volume(int $siteId, int $zoneId): array
    {
        $this->packageVolumes ??= DB::table('packaging_volumes')->where('company_id', $this->stock->companyId())->pluck('volume_m3', 'packaging_type_id')
            ->map(fn ($volume) => (string) $volume)->all();
        $rows = $this->stock->lots([$siteId])->where('l.zone_id', $zoneId)->leftJoin('units as u', 'u.id', '=', 'l.unit_id')
            ->leftJoin('waste_types as t', 't.id', '=', 'l.waste_type_id')->orderBy('l.id')
            ->get(['l.lot_number', 'l.packaging_type_id', 'l.package_count', 'l.net_weight_kg', 'l.quantity', 'u.code as unit', 't.density_kg_m3']);

        $used = '0.0000';
        $lines = [];
        $missing = [];
        foreach ($rows as $row) {
            $kind = (string) $this->codes->code('packaging_types', $row->packaging_type_id === null ? null : (int) $row->packaging_type_id);
            if ($row->packaging_type_id !== null) {
                $one = $this->packageVolumes[(int) $row->packaging_type_id] ?? null;
                $volume = $one === null || $row->package_count === null ? null : bcmul($one, (string) $row->package_count, 4);
            } else {
                $volume = $row->quantity !== null && UnitConversion::dimension($row->unit) === 'volume' ? UnitConversion::convert((string) $row->quantity, $row->unit, 'm3')
                    : ($row->net_weight_kg === null ? null : UnitConversion::convert((string) $row->net_weight_kg, 'kg', 'm3', $row->density_kg_m3 === null ? null : (string) $row->density_kg_m3));
            }
            if ($volume === null) {
                $missing[] = (string) $row->lot_number;

                continue;
            }
            $used = bcadd($used, $volume, 4);
            $lines[$kind] ??= ['packaging_type' => $kind, 'packages' => $kind === '' ? null : 0, 'lots' => 0, 'volume_m3' => '0.0000'];
            $lines[$kind]['lots']++;
            $lines[$kind]['volume_m3'] = bcadd($lines[$kind]['volume_m3'], $volume, 4);
            if ($kind !== '') {
                $lines[$kind]['packages'] += (int) $row->package_count;
            }
        }

        return ['used' => bcadd($used, '0', 3), 'lines' => array_values(array_map(fn (array $line) => ['volume_m3' => bcadd($line['volume_m3'], '0', 3)] + $line, $lines)), 'missing' => $missing];
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
            // Packagings in the order their first lot arrived, on MySQL and MariaDB alike (a GROUP BY alone has no order).
            ->reorder()->orderByRaw('MIN(l.id)')
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
