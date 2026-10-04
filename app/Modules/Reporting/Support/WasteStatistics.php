<?php

namespace App\Modules\Reporting\Support;

use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * M6-01 / M6-02 / M4-05: every figure is computed from the operational records, never typed in.
 *
 * - "Generated" waste = lots created by an operator in the period. Lots born from a split or a grouping are
 *   excluded: their weight was already counted when the original lots were created.
 * - "Evacuated" waste = pickup lines that left the site in the period, at the received weight when the
 *   provider has entered it, otherwise at the departure weight.
 * - Recovery rate and landfill share are measured on the evacuated waste whose treatment channel has been
 *   declared by the provider; the undeclared part is reported separately instead of being guessed.
 */
final class WasteStatistics
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * @param  list<int>|null  $siteIds  null = every site
     * @return array<string, mixed>
     */
    public function dashboard(Carbon $from, Carbon $to, ?array $siteIds): array
    {
        $generated = fn () => $this->generated($from, $to, $siteIds);
        $evacuated = fn () => $this->evacuated($from, $to, $siteIds);

        $byChannel = $evacuated()->leftJoin('treatment_channels as ch', 'ch.id', '=', 'pl.actual_treatment_channel_id')
            ->groupBy('ch.code', 'ch.is_valorization', 'ch.is_landfill', 'ch.hierarchy_rank')->orderBy('ch.hierarchy_rank')
            ->get(['ch.code', 'ch.is_valorization', 'ch.is_landfill', DB::raw($this->weight().' as kg')]);
        $declared = $byChannel->whereNotNull('code');
        $declaredKg = $this->sum($declared);
        $recoveredKg = $this->sum($declared->where('is_valorization', 1));
        $landfillKg = $this->sum($declared->where('is_landfill', 1));
        $generatedKg = (string) ($generated()->sum('l.net_weight_kg') ?: '0');
        $pieces = (int) DB::table('production_volumes')->where('company_id', $this->context->companyId())
            ->whereBetween('period_month', [$from->copy()->startOfMonth()->toDateString(), $to->toDateString()])
            ->when($siteIds !== null, fn (Builder $query) => $query->whereIn('site_id', $siteIds))->sum('pieces_produced');
        $money = $evacuated()->whereNotNull('pl.net_amount')->selectRaw(
            'COALESCE(SUM(CASE WHEN pl.net_amount > 0 THEN pl.net_amount ELSE 0 END), 0) as revenue, '
            .'COALESCE(SUM(CASE WHEN pl.net_amount < 0 THEN -pl.net_amount ELSE 0 END), 0) as cost'
        )->first();

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'totals' => [
                'generated_kg' => $this->kg($generatedKg),
                'generated_lots' => $generated()->count(),
                'hazardous_kg' => $this->kg((string) ($generated()->where('l.is_hazardous', true)->sum('l.net_weight_kg') ?: '0')),
                'evacuated_kg' => $this->kg($this->sum($byChannel)),
                'recovered_kg' => $this->kg($recoveredKg),
                'landfill_kg' => $this->kg($landfillKg),
                'undeclared_channel_kg' => $this->kg($this->sum($byChannel->whereNull('code'))),
                'recovery_rate_pct' => $this->pct($recoveredKg, $declaredKg),
                'landfill_share_pct' => $this->pct($landfillKg, $declaredKg),
                // M6-02: needs the number of pieces produced, entered per site and month.
                'pieces_produced' => $pieces,
                'kg_per_piece' => $pieces === 0 ? null : bcdiv($generatedKg, (string) $pieces, 4),
                'revenue' => bcadd((string) $money->revenue, '0', 3),
                'cost' => bcadd((string) $money->cost, '0', 3),
                'net_balance' => bcsub((string) $money->revenue, (string) $money->cost, 3),
            ],
            'by_month' => $this->byMonth($from, $to, $siteIds),
            'by_waste_type' => $generated()->join('waste_types as t', 't.id', '=', 'l.waste_type_id')
                ->groupBy('t.ulid', 't.name', 't.is_hazardous')->orderByDesc('kg')->limit(30)
                ->get(['t.ulid as waste_type', 't.name', 't.is_hazardous', DB::raw('SUM(l.net_weight_kg) as kg'), DB::raw('COUNT(*) as lots')])
                ->map(fn ($row) => ['waste_type' => $row->waste_type, 'name' => $row->name, 'is_hazardous' => (bool) $row->is_hazardous,
                    'kg' => $this->kg((string) $row->kg), 'lots' => (int) $row->lots])->all(),
            'by_site' => $generated()->join('sites as s', 's.id', '=', 'l.site_id')->groupBy('s.ulid', 's.name')->orderByDesc('kg')
                ->get(['s.ulid as site', 's.name', DB::raw('SUM(l.net_weight_kg) as kg'), DB::raw('COUNT(*) as lots')])
                ->map(fn ($row) => ['site' => $row->site, 'name' => $row->name, 'kg' => $this->kg((string) $row->kg), 'lots' => (int) $row->lots])->all(),
            'by_channel' => $byChannel->map(fn ($row) => [
                'channel' => $row->code, 'is_valorization' => (bool) $row->is_valorization, 'is_landfill' => (bool) $row->is_landfill,
                'kg' => $this->kg((string) $row->kg),
            ])->values()->all(),
        ];
    }

    /**
     * Generated / evacuated / balance per calendar month, months without activity included.
     *
     * @param  list<int>|null  $siteIds
     * @return list<array<string, mixed>>
     */
    public function byMonth(Carbon $from, Carbon $to, ?array $siteIds): array
    {
        $generated = $this->generated($from, $to, $siteIds)->groupByRaw("DATE_FORMAT(l.generated_at, '%Y-%m')")
            ->selectRaw("DATE_FORMAT(l.generated_at, '%Y-%m') as month, SUM(l.net_weight_kg) as kg")->pluck('kg', 'month');
        $evacuated = $this->evacuated($from, $to, $siteIds)->groupByRaw("DATE_FORMAT(p.collected_at, '%Y-%m')")
            ->selectRaw("DATE_FORMAT(p.collected_at, '%Y-%m') as month, {$this->weight()} as kg, COALESCE(SUM(pl.net_amount), 0) as net")->get()->keyBy('month');

        $months = [];
        for ($cursor = $from->copy()->startOfMonth(); $cursor->lessThanOrEqualTo($to); $cursor->addMonth()) {
            $key = $cursor->format('Y-m');
            $months[] = [
                'month' => $key,
                'generated_kg' => $this->kg((string) ($generated[$key] ?? '0')),
                'evacuated_kg' => $this->kg((string) ($evacuated[$key]->kg ?? '0')),
                'net_balance' => bcadd((string) ($evacuated[$key]->net ?? '0'), '0', 3),
            ];
        }

        return $months;
    }

    /**
     * @param  list<int>|null  $siteIds
     */
    private function generated(Carbon $from, Carbon $to, ?array $siteIds): Builder
    {
        return DB::table('waste_lots as l')->where('l.company_id', $this->context->companyId())
            ->where('l.origin_type', 'created')->whereNull('l.deleted_at')
            ->where('l.generated_at', '>=', $from)->where('l.generated_at', '<', $to->copy()->addDay()->startOfDay())
            ->when($siteIds !== null, fn (Builder $query) => $query->whereIn('l.site_id', $siteIds));
    }

    /**
     * @param  list<int>|null  $siteIds
     */
    private function evacuated(Carbon $from, Carbon $to, ?array $siteIds): Builder
    {
        return DB::table('pickup_lots as pl')->join('pickups as p', 'p.id', '=', 'pl.pickup_id')
            ->where('p.company_id', $this->context->companyId())
            ->whereIn('pl.line_status', ['loaded', 'received', 'treated'])
            ->where('p.collected_at', '>=', $from)->where('p.collected_at', '<', $to->copy()->addDay()->startOfDay())
            ->when($siteIds !== null, fn (Builder $query) => $query->whereIn('p.site_id', $siteIds));
    }

    private function weight(): string
    {
        return 'SUM(COALESCE(pl.received_weight_kg, pl.departure_weight_kg))';
    }

    /**
     * @param  iterable<object>  $rows
     */
    private function sum(iterable $rows): string
    {
        $total = '0';
        foreach ($rows as $row) {
            $total = bcadd($total, (string) $row->kg, 3);
        }

        return $total;
    }

    private function kg(string $value): string
    {
        return bcadd($value, '0', 3);
    }

    private function pct(string $part, string $whole): ?string
    {
        return bccomp($whole, '0', 3) === 0 ? null : bcdiv(bcmul($part, '100', 6), $whole, 1);
    }
}
