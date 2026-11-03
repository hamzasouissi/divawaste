<?php

namespace App\Modules\Contracts\Support;

use App\Modules\Contracts\Models\Contract;
use App\Modules\Pickups\Models\Pickup;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What a contract means for pickups: which contract a pickup is made under, the prices of its lots and the fees it
 * adds, and how the contract stands — pickups done against what it plans, the next one due, the money so far.
 * Raw reads on purpose: a provider reads the contract it is a party to, never the company's other data.
 */
final class ContractTerms
{
    /** Days of one period of each frequency, to say when the next pickup is due. */
    private const PERIOD_DAYS = ['weekly' => 7, 'biweekly' => 14, 'monthly' => 30, 'quarterly' => 91];

    /**
     * The active contract under which a party receives (or carries) waste of the site on a date, or null. Several
     * active contracts never overlap for one party, role and site (checked at activation), so there is one at most.
     *
     * @param  array{provider_company_id?: int|null, external_partner_id?: int|null}  $party
     */
    public static function applicable(int $companyId, array $party, string $role, int $siteId, string $date): ?int
    {
        if (($party['provider_company_id'] ?? null) === null && ($party['external_partner_id'] ?? null) === null) {
            return null;
        }
        $id = DB::table('contracts')->where('company_id', $companyId)->where('status', 'active')->where('party_role', $role)
            ->when(($party['provider_company_id'] ?? null) !== null, fn ($q) => $q->where('provider_company_id', $party['provider_company_id']),
                fn ($q) => $q->where('external_partner_id', $party['external_partner_id']))
            ->where(fn ($q) => $q->whereNull('site_id')->orWhere('site_id', $siteId))
            ->whereDate('starts_on', '<=', $date)->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $date))
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * The prices of the receiver's contract for the lines that left, in the form PickupWorkflow::price takes them. A
     * waste type takes its own line of the contract, else the line for every waste type; a price by weight needs a
     * measured weight (the others wait to be priced by hand), a flat price is an amount per lot.
     *
     * @return array<string, array{direction: string, pricing_mode: string, unit_price: string}>
     */
    public static function prices(Pickup $pickup): array
    {
        if ($pickup->contract_id === null) {
            return [];
        }
        $prices = DB::table('contract_prices')->where('contract_id', $pickup->contract_id)->get(['waste_type_id', 'pricing_mode', 'unit_price', 'direction'])
            ->keyBy(fn ($row) => $row->waste_type_id === null ? 'any' : (string) $row->waste_type_id);
        if ($prices->isEmpty()) {
            return [];
        }
        $lines = DB::table('pickup_lots as pl')->join('waste_lots as l', 'l.id', '=', 'pl.waste_lot_id')->where('pl.pickup_id', $pickup->id)
            ->whereIn('pl.line_status', ['loaded', 'received', 'treated'])->get(['pl.lot_number_snapshot', 'pl.received_weight_kg', 'pl.departure_weight_kg', 'pl.weight_basis', 'l.waste_type_id']);
        $out = [];
        foreach ($lines as $line) {
            $price = $prices[(string) $line->waste_type_id] ?? $prices['any'] ?? null;
            $measured = $line->received_weight_kg !== null || ($line->departure_weight_kg !== null && $line->weight_basis !== 'estimated');
            if ($price === null || ($price->pricing_mode !== 'flat' && ! $measured)) {
                continue;
            }
            $out[$line->lot_number_snapshot] = ['direction' => $price->direction, 'pricing_mode' => $price->pricing_mode, 'unit_price' => (string) $price->unit_price];
        }

        return $out;
    }

    /**
     * The fees one pickup adds under the contracts of its receiver and its transporter, signed for the company, or
     * null when neither contract has a fee per pickup.
     */
    public static function pickupFees(Pickup $pickup): ?string
    {
        $contracts = array_filter([$pickup->contract_id, $pickup->transport_contract_id]);
        $fees = DB::table('contracts')->whereIn('id', $contracts)->whereNotNull('pickup_fee')->get(['pickup_fee', 'pickup_fee_direction']);
        $charges = DB::table('contract_charges')->whereIn('contract_id', $contracts)->whereIn('basis', ['per_pickup', 'per_tonne'])->get(['amount', 'basis', 'direction']);
        if ($fees->isEmpty() && $charges->isEmpty()) {
            return null;
        }
        $sum = $fees->reduce(fn (string $sum, $fee) => $fee->pickup_fee_direction === 'cost'
            ? bcsub($sum, (string) $fee->pickup_fee, 3) : bcadd($sum, (string) $fee->pickup_fee, 3), '0');
        // per tonne: on the weight received (a pickup with no known received weight adds nothing for these)
        $tonnes = bcdiv((string) (DB::table('pickup_lots')->where('pickup_id', $pickup->id)->sum('received_weight_kg') ?: '0'), '1000', 6);
        foreach ($charges as $charge) {
            $amount = $charge->basis === 'per_tonne' ? bcmul((string) $charge->amount, $tonnes, 3) : (string) $charge->amount;
            $sum = $charge->direction === 'cost' ? bcsub($sum, $amount, 3) : bcadd($sum, $amount, 3);
        }

        return $sum;
    }

    /**
     * The charges of a contract as both parties read them.
     *
     * @return list<array{label: string, amount: string, basis: string, direction: string}>
     */
    public static function charges(Contract $contract): array
    {
        return DB::table('contract_charges')->where('contract_id', $contract->id)->orderBy('position')->get(['label', 'amount', 'basis', 'direction'])
            ->map(fn ($row) => ['label' => $row->label, 'amount' => (string) $row->amount, 'basis' => $row->basis, 'direction' => $row->direction])->values()->all();
    }

    /**
     * The contract as both parties read it, with how it stands today.
     *
     * @return array<string, mixed>
     */
    public static function present(Contract $contract, bool $detail = true): array
    {
        $partner = $contract->provider_company_id !== null
            ? DB::table('companies')->where('id', $contract->provider_company_id)->first(['ulid', 'legal_name', 'city'])
            : DB::table('external_partners')->where('id', $contract->external_partner_id)->first(['ulid', 'legal_name', 'city']);
        $company = DB::table('companies')->where('id', $contract->company_id)->first(['ulid', 'legal_name', 'city']);
        $site = $contract->site_id === null ? null : DB::table('sites')->where('id', $contract->site_id)->first(['ulid', 'code', 'name']);
        $prices = DB::table('contract_prices as p')->leftJoin('waste_types as t', 't.id', '=', 'p.waste_type_id')->where('p.contract_id', $contract->id)
            ->orderByRaw('p.waste_type_id IS NULL')->orderBy('t.name')->get(['t.ulid as waste_type', 't.name as waste_type_name', 'p.pricing_mode', 'p.unit_price', 'p.direction'])
            ->map(fn ($row) => ['waste_type' => $row->waste_type === null ? null : ['ulid' => $row->waste_type, 'name' => $row->waste_type_name],
                'pricing_mode' => $row->pricing_mode, 'unit_price' => (string) $row->unit_price, 'direction' => $row->direction])->values()->all();

        return [
            'ulid' => $contract->ulid,
            'contract_number' => $contract->contract_number,
            'reference' => $contract->reference,
            'title' => $contract->title,
            'status' => $contract->status,
            // draft | upcoming | active | expired | terminated — what the dates say today.
            'state' => $contract->state(),
            'company' => $company === null ? null : (array) $company,
            'party' => $partner === null ? null : (array) $partner + ['external' => $contract->external_partner_id !== null],
            'party_role' => $contract->party_role,
            'site' => $site === null ? null : (array) $site,
            'starts_on' => $contract->starts_on->toDateString(),
            'ends_on' => $contract->ends_on?->toDateString(),
            'frequency' => $contract->frequency,
            'pickups_per_period' => $contract->pickups_per_period,
            'weekdays' => $contract->weekdays ?? [],
            'planned_pickups' => $contract->planned_pickups,
            'prices' => $prices,
            'charges' => self::charges($contract),
            'pickup_fee' => $contract->pickup_fee === null ? null : (string) $contract->pickup_fee,
            'pickup_fee_direction' => $contract->pickup_fee_direction,
            'monthly_fee' => $contract->monthly_fee === null ? null : (string) $contract->monthly_fee,
            'monthly_fee_direction' => $contract->monthly_fee_direction,
            'payment_terms_days' => $contract->payment_terms_days,
            'currency_code' => $contract->currency_code,
            'notes' => $contract->notes,
            'activated_at' => $contract->activated_at?->toIso8601ZuluString(),
            'terminated_at' => $contract->terminated_at?->toIso8601ZuluString(),
            'termination_reason' => $contract->termination_reason,
            'progress' => self::progress($contract),
            'pickups' => $detail ? DB::table('pickups')->where(fn ($q) => $q->where('contract_id', $contract->id)->orWhere('transport_contract_id', $contract->id))
                ->orderByDesc('requested_date')->limit(100)->get(['ulid', 'pickup_number', 'status', 'requested_date', 'confirmed_date', 'collected_at', 'received_weight_kg', 'contract_fees_amount'])
                ->map(fn ($row) => (array) $row)->values()->all() : null,
        ];
    }

    /**
     * How the contract stands: pickups done (collected or later) in all and in the current period, against what it
     * plans; when the next one is due and whether it is late; the money so far — the priced lots of its pickups, the
     * fees per pickup, and the monthly fee for the months begun — signed for the company.
     *
     * @return array<string, mixed>
     */
    public static function progress(Contract $contract): array
    {
        $today = Carbon::today();
        $pickups = DB::table('pickups')->where(fn ($q) => $q->where('contract_id', $contract->id)->orWhere('transport_contract_id', $contract->id))
            ->get(['id', 'status', 'collected_at', 'contract_id', 'contract_fees_amount']);
        $done = $pickups->filter(fn ($row) => in_array($row->status, ['collected', 'received', 'completed'], true));
        $open = $pickups->filter(fn ($row) => in_array($row->status, ['draft', 'requested', 'confirmed'], true));
        $last = $done->max('collected_at');
        $days = self::PERIOD_DAYS[$contract->frequency] ?? null;
        $perPeriod = max(1, (int) ($contract->pickups_per_period ?? 1));
        $periodStart = $days === null ? null : self::periodStart($contract, $today, $days);
        $inPeriod = $periodStart === null ? null : $done->filter(fn ($row) => $row->collected_at !== null && Carbon::parse($row->collected_at)->gte($periodStart))->count();
        // The next pickup is due one interval after the last one (an interval = the period shared by its pickups).
        $nextDue = null;
        if ($days !== null && in_array($contract->state(), ['active', 'upcoming'], true)) {
            $interval = (int) floor($days / $perPeriod);
            $from = $last !== null ? Carbon::parse($last)->startOfDay() : $contract->starts_on->copy()->subDays($interval);
            $nextDue = $from->copy()->addDays(max(1, $interval));
            if ($contract->starts_on->isAfter($nextDue)) {
                $nextDue = $contract->starts_on->copy();
            }
            $nextDue = self::onChosenDay($nextDue, $contract->weekdays ?? []);
        }
        $lines = DB::table('pickup_lots')->whereIn('pickup_id', $pickups->where('contract_id', $contract->id)->pluck('id'))->whereNotNull('net_amount')->sum('net_amount');
        $fees = $pickups->whereNotNull('contract_fees_amount')->reduce(fn (string $sum, $row) => bcadd($sum, (string) $row->contract_fees_amount, 3), '0');
        $months = 0;
        if ($contract->monthly_fee !== null && $contract->status !== 'draft') {
            $end = collect([$today, $contract->ends_on, $contract->terminated_at === null ? null : Carbon::parse($contract->terminated_at)])->filter()->min();
            $months = $end->lt($contract->starts_on) ? 0 : (int) $contract->starts_on->diffInMonths($end) + 1;
        }
        $monthly = $contract->monthly_fee === null ? '0' : bcmul((string) $contract->monthly_fee, (string) $months, 3);
        $monthly = $contract->monthly_fee_direction === 'cost' ? bcsub('0', $monthly, 3) : $monthly;
        if ($contract->status !== 'draft') {
            $begun = $contract->starts_on->lte($today) ? (int) $contract->starts_on->diffInMonths(collect([$today, $contract->ends_on])->filter()->min()) + 1 : 0;
            foreach (DB::table('contract_charges')->where('contract_id', $contract->id)->whereIn('basis', ['per_month', 'once'])->get(['amount', 'basis', 'direction']) as $charge) {
                $amount = $charge->basis === 'per_month' ? bcmul((string) $charge->amount, (string) $begun, 3) : ($begun > 0 ? (string) $charge->amount : '0');
                $monthly = $charge->direction === 'cost' ? bcsub($monthly, $amount, 3) : bcadd($monthly, $amount, 3);
            }
        }

        return [
            'pickups_done' => $done->count(),
            'pickups_open' => $open->count(),
            'planned_pickups' => $contract->planned_pickups,
            'remaining' => $contract->planned_pickups === null ? null : max(0, $contract->planned_pickups - $done->count()),
            'period' => $days === null ? null : ['starts_on' => $periodStart?->toDateString(), 'done' => $inPeriod, 'expected' => $perPeriod],
            'last_pickup_at' => $last === null ? null : Carbon::parse($last, 'UTC')->toIso8601ZuluString(),
            'next_due_on' => $nextDue?->toDateString(),
            // An active contract whose next pickup should already have taken place, with none requested since.
            'late' => $nextDue !== null && $contract->state() === 'active' && $nextDue->lt($today) && $open->isEmpty(),
            'amounts' => ['lots' => bcadd((string) $lines, '0', 3), 'pickup_fees' => $fees, 'monthly_fees' => $monthly, 'months' => $months,
                'total' => bcadd(bcadd((string) $lines, $fees, 3), $monthly, 3)],
        ];
    }

    /**
     * The first of the contract's chosen days (mon … sun) on or after a date; the date itself when no day is chosen.
     *
     * @param  list<string>  $weekdays
     */
    public static function onChosenDay(CarbonInterface $date, array $weekdays): CarbonInterface
    {
        $days = array_flip(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun']);
        $chosen = array_values(array_filter(array_map(fn (string $day) => $days[$day] ?? null, $weekdays), fn ($n) => $n !== null));
        if ($chosen === []) {
            return $date;
        }
        $day = $date->copy();
        for ($i = 0; $i < 7 && ! in_array(((int) $day->dayOfWeekIso) - 1, $chosen, true); $i++) {
            $day = $day->addDay();
        }

        return $day;
    }

    /**
     * The start of the current period, counted from the start of the contract.
     */
    private static function periodStart(Contract $contract, CarbonInterface $today, int $days): CarbonInterface
    {
        if ($today->lt($contract->starts_on)) {
            return $contract->starts_on->copy();
        }
        if ($contract->frequency === 'monthly') {
            return $today->copy()->startOfMonth()->max($contract->starts_on);
        }
        if ($contract->frequency === 'quarterly') {
            return $today->copy()->firstOfQuarter()->max($contract->starts_on);
        }
        $elapsed = (int) $contract->starts_on->diffInDays($today);

        return $contract->starts_on->copy()->addDays(intdiv($elapsed, $days) * $days);
    }
}
