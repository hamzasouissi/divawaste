<?php

namespace App\Modules\Contracts\Actions;

use App\Modules\Common\Exceptions\BusinessRuleViolation;
use App\Modules\Common\Support\NumberSequence;
use App\Modules\Contracts\Models\Contract;
use App\Modules\Contracts\Models\ContractCharge;
use App\Modules\Contracts\Models\ContractPrice;
use App\Modules\Contracts\Support\ContractTerms;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Actions\NotifyUsers;
use App\Modules\Providers\Models\ExternalPartner;
use App\Modules\Providers\Models\ProviderPartnership;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * A contract with a receiver or a transporter: written as a draft, activated once its terms are right, terminated when
 * it stops. Only an active contract applies to pickups. Two active contracts never overlap for the same party, role and
 * site on the same days, so that a pickup always knows its contract.
 */
final class ManageContracts
{
    public function __construct(private readonly TenantContext $context, private readonly NotifyUsers $notify) {}

    /**
     * @param  array<string, mixed>  $data  the fields of the contract, with `prices` [{waste_type_id|null, pricing_mode, unit_price, direction}]
     */
    public function create(array $data, User $actor): Contract
    {
        return DB::transaction(function () use ($data, $actor) {
            $year = now()->format('Y');
            $this->rhythm($this->fields($data));
            $contract = Contract::query()->create($this->fields($data) + [
                'contract_number' => NumberSequence::next($this->context->companyId(), 'contract', "CTR-{$year}-", $year, 4),
                'provider_company_id' => $data['provider_company_id'] ?? null, 'external_partner_id' => $data['external_partner_id'] ?? null,
                'party_role' => $data['party_role'],
                'currency_code' => (string) DB::table('companies')->where('id', $this->context->companyId())->value('currency_code'),
                'created_by_user_id' => $actor->id,
            ]);
            $this->prices($contract, $data['prices'] ?? []);
            $this->charges($contract, $data['charges'] ?? []);

            return $contract;
        });
    }

    /**
     * The terms may be changed while the contract runs: what is changed applies to the pickups received from then on.
     * The party and its role are fixed once the contract is active.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Contract $contract, array $data): Contract
    {
        return DB::transaction(function () use ($contract, $data) {
            $contract = Contract::query()->whereKey($contract->id)->lockForUpdate()->firstOrFail();
            if ($contract->status === 'terminated') {
                throw new BusinessRuleViolation('CONTRACT_TERMINATED', "Contract {$contract->contract_number} is terminated: it can no longer be changed.");
            }
            $fields = $this->fields($data);
            $this->rhythm($fields, $contract);
            if ($contract->status === 'draft') {
                $fields += array_intersect_key($data, array_flip(['provider_company_id', 'external_partner_id', 'party_role']));
            }
            $contract->forceFill($fields)->save();
            if (array_key_exists('prices', $data)) {
                $this->prices($contract, $data['prices']);
            }
            if (array_key_exists('charges', $data)) {
                $this->charges($contract, $data['charges']);
            }
            if ($contract->status === 'active') {
                $this->assertNoOverlap($contract);
            }

            return $contract;
        });
    }

    public function activate(Contract $contract): Contract
    {
        $contract = DB::transaction(function () use ($contract) {
            $contract = Contract::query()->whereKey($contract->id)->lockForUpdate()->firstOrFail();
            if ($contract->status !== 'draft') {
                throw new BusinessRuleViolation('CONTRACT_NOT_DRAFT', "Contract {$contract->contract_number} is already {$contract->status}.");
            }
            $this->assertRole($contract);
            $this->assertNoOverlap($contract);
            $contract->forceFill(['status' => 'active', 'activated_at' => now()])->save();
            // A provider of the platform the company has a contract with is a partner: its pickups can be requested.
            if ($contract->provider_company_id !== null) {
                $partnership = ProviderPartnership::query()->firstOrCreate(['provider_company_id' => $contract->provider_company_id],
                    ['status' => 'active', 'started_at' => now(), 'created_by_user_id' => $contract->created_by_user_id]);
                if ($partnership->status !== 'active') {
                    $partnership->forceFill(['status' => 'active', 'ended_at' => null])->save();
                }
            }

            return $contract;
        });
        $this->tellParty($contract, 'contract.activated');

        return $contract;
    }

    /**
     * Ends the contract on a date (today by default): pickups requested after that date no longer fall under it.
     */
    public function terminate(Contract $contract, ?string $reason, ?string $on = null): Contract
    {
        $contract = DB::transaction(function () use ($contract, $reason, $on) {
            $contract = Contract::query()->whereKey($contract->id)->lockForUpdate()->firstOrFail();
            if ($contract->status !== 'active') {
                throw new BusinessRuleViolation('CONTRACT_NOT_ACTIVE', "Contract {$contract->contract_number} is not active.");
            }
            $end = Carbon::parse($on ?? now()->toDateString());
            if ($end->lt($contract->starts_on)) {
                $end = $contract->starts_on->copy();
            }
            $contract->forceFill(['status' => 'terminated', 'terminated_at' => now(), 'termination_reason' => $reason,
                'ends_on' => $contract->ends_on === null || $end->lt($contract->ends_on) ? $end->toDateString() : $contract->ends_on->toDateString()])->save();

            return $contract;
        });
        $this->tellParty($contract, 'contract.terminated');

        return $contract;
    }

    /**
     * Contracts ending within 30 days: the company and the provider are told once.
     */
    public static function alertEnding(): int
    {
        $rows = DB::table('contracts')->where('status', 'active')->whereNull('ending_notified_at')->whereNotNull('ends_on')
            ->whereDate('ends_on', '>=', today())->whereDate('ends_on', '<=', today()->addDays(30))->get(['id', 'company_id']);
        foreach ($rows as $row) {
            app(TenantContext::class)->runAs(Company::query()->findOrFail($row->company_id), function () use ($row) {
                $contract = Contract::query()->findOrFail($row->id);
                $params = self::params($contract);
                app(NotifyUsers::class)->toCompanyPermission((int) $contract->company_id, 'contracts.manage', 'contract.ending_soon', $params, '/contracts/'.$contract->ulid);
                app(self::class)->tellParty($contract, 'contract.ending_soon');
                $contract->forceFill(['ending_notified_at' => now()])->save();
            });
        }

        return $rows->count();
    }

    /**
     * The next pickup of an active contract is due tomorrow or today and none is requested: whoever requests pickups
     * is told, once a day, with a link that opens the request already filled (the partner, the date).
     */
    public static function remindDue(): int
    {
        $sent = 0;
        $rows = DB::table('contracts')->where('status', 'active')->whereNotIn('frequency', ['on_demand'])
            ->whereDate('starts_on', '<=', today()->addDay())->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', today()))
            ->get(['id', 'company_id']);
        foreach ($rows as $row) {
            app(TenantContext::class)->runAs(Company::query()->findOrFail($row->company_id), function () use ($row, &$sent) {
                $contract = Contract::query()->findOrFail($row->id);
                $progress = ContractTerms::progress($contract);
                $due = $progress['next_due_on'] ?? null;
                if ($due === null || $progress['pickups_open'] > 0 || $due > today()->addDay()->toDateString() || $due < today()->toDateString()) {
                    return;
                }
                if (! Cache::add("contracts.due.{$contract->id}.{$due}.".today()->toDateString(), true, now()->addDays(2))) {
                    return;  // told today already
                }
                $party = $contract->provider_company_id !== null
                    ? ['key' => 'c:'.DB::table('companies')->where('id', $contract->provider_company_id)->value('ulid'), 'name' => DB::table('companies')->where('id', $contract->provider_company_id)->value('legal_name')]
                    : ['key' => 'x:'.DB::table('external_partners')->where('id', $contract->external_partner_id)->value('ulid'), 'name' => DB::table('external_partners')->where('id', $contract->external_partner_id)->value('legal_name')];
                $params = ['number' => $contract->contract_number, 'title' => $contract->title, 'partner' => (string) $party['name'],
                    'date' => Carbon::parse($due)->format('d/m/Y'), 'when' => $due === today()->toDateString() ? 'today' : 'tomorrow'];
                $link = '/pickups/new?provider='.$party['key'].'&date='.$due;
                if ($contract->site_id !== null) {
                    app(NotifyUsers::class)->toSitePermission((int) $contract->site_id, 'pickups.request', 'contract.pickup_due', $params, $link);
                } else {
                    app(NotifyUsers::class)->toCompanyPermission((int) $contract->company_id, 'pickups.request', 'contract.pickup_due', $params, $link);
                }
                $sent++;
            });
        }

        return $sent;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function fields(array $data): array
    {
        $keys = ['reference', 'title', 'site_id', 'starts_on', 'ends_on', 'frequency', 'pickups_per_period', 'weekdays', 'planned_pickups',
            'pickup_fee', 'pickup_fee_direction', 'monthly_fee', 'monthly_fee_direction', 'payment_terms_days', 'notes'];
        $fields = array_intersect_key($data, array_flip($keys));
        if (($fields['frequency'] ?? null) === 'on_demand') {
            $fields['pickups_per_period'] = null;
        }

        return $fields;
    }

    /**
     * With chosen days (weekly, every two weeks) there is one pickup on each chosen day: the number of pickups of the
     * period is the number of days (the application sends it so). No day chosen: the number alone, any day.
     *
     * @param  array<string, mixed>  $fields  what is written, over what the contract already says
     */
    private function rhythm(array $fields, ?Contract $contract = null): void
    {
        $frequency = $fields['frequency'] ?? $contract?->frequency;
        $perPeriod = array_key_exists('pickups_per_period', $fields) ? $fields['pickups_per_period'] : $contract?->pickups_per_period;
        $days = array_key_exists('weekdays', $fields) ? ($fields['weekdays'] ?? []) : ($contract?->weekdays ?? []);
        $weeks = ['weekly' => 1, 'biweekly' => 2][$frequency] ?? null;
        if ($weeks !== null && $perPeriod !== null && $days !== [] && (int) $perPeriod !== count($days)) {
            throw new BusinessRuleViolation('CONTRACT_PACE_DAYS', 'More pickups per period than the chosen days allow.',
                ['pickups_per_period' => (int) $perPeriod, 'days' => count($days), 'weeks' => $weeks]);
        }
    }

    /**
     * @param  list<array{label: string, amount: string, basis: string, direction: string}>  $charges
     */
    private function charges(Contract $contract, array $charges): void
    {
        ContractCharge::query()->where('contract_id', $contract->id)->delete();
        foreach (array_values($charges) as $position => $charge) {
            ContractCharge::query()->create(['contract_id' => $contract->id, 'label' => trim((string) $charge['label']), 'amount' => bcadd((string) $charge['amount'], '0', 3),
                'basis' => $charge['basis'], 'direction' => $charge['direction'], 'position' => $position]);
        }
    }

    /**
     * @param  list<array{waste_type_id: int|null, pricing_mode: string, unit_price: string, direction: string}>  $prices
     */
    private function prices(Contract $contract, array $prices): void
    {
        $types = array_map(fn (array $price) => $price['waste_type_id'] ?? 'any', $prices);
        if (count($types) !== count(array_unique($types))) {
            throw new BusinessRuleViolation('CONTRACT_PRICE_TWICE', 'A waste type has one price in a contract (and one line at most for every other waste type).');
        }
        ContractPrice::query()->where('contract_id', $contract->id)->delete();
        foreach ($prices as $price) {
            ContractPrice::query()->create(['contract_id' => $contract->id, 'waste_type_id' => $price['waste_type_id'] ?? null, 'pricing_mode' => $price['pricing_mode'],
                'unit_price' => bcadd((string) $price['unit_price'], '0', 3), 'direction' => $price['direction']]);
        }
    }

    /**
     * The party does what the contract says: a receiver collects, recycles or sorts waste; a transporter carries it.
     */
    private function assertRole(Contract $contract): void
    {
        $receiver = $contract->party_role === 'receiver';
        if ($contract->provider_company_id !== null) {
            $profile = DB::table('provider_profiles')->where('company_id', $contract->provider_company_id)->first(['is_collector', 'is_transporter', 'is_recycler', 'is_sorter']);
            $ok = $profile !== null && ($receiver ? ($profile->is_collector || $profile->is_recycler || $profile->is_sorter) : $profile->is_transporter);
            $name = (string) DB::table('companies')->where('id', $contract->provider_company_id)->value('legal_name');
        } else {
            $partner = ExternalPartner::query()->findOrFail($contract->external_partner_id);
            $ok = $receiver ? $partner->receives() : $partner->is_transporter;
            $name = $partner->legal_name;
        }
        if (! $ok) {
            throw new BusinessRuleViolation('PARTNER_ROLE_MISMATCH', $receiver ? "{$name} does not receive waste (collector, recycler or sorter): it cannot be the receiver of this contract."
                : "{$name} is not recorded as a transporter.");
        }
    }

    private function assertNoOverlap(Contract $contract): void
    {
        $other = DB::table('contracts')->where('company_id', $contract->company_id)->where('status', 'active')->where('id', '!=', $contract->id)
            ->where('party_role', $contract->party_role)
            ->when($contract->provider_company_id !== null, fn ($q) => $q->where('provider_company_id', $contract->provider_company_id), fn ($q) => $q->where('external_partner_id', $contract->external_partner_id))
            // A contract of every site overlaps any other; one of a site, those of every site and of that site.
            ->when($contract->site_id !== null, fn ($q) => $q->where(fn ($q) => $q->whereNull('site_id')->orWhere('site_id', $contract->site_id)))
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $contract->starts_on))
            ->when($contract->ends_on !== null, fn ($q) => $q->whereDate('starts_on', '<=', $contract->ends_on))
            ->value('contract_number');
        if ($other !== null) {
            throw new BusinessRuleViolation('CONTRACT_OVERLAP', "Contract {$other} already covers this party in this role, on this site, on some of these days: end it or change the dates.", ['contract' => $other]);
        }
    }

    /**
     * @return array<string, scalar|null>
     */
    private static function params(Contract $contract): array
    {
        return ['number' => $contract->contract_number, 'title' => $contract->title, 'company' => (string) DB::table('companies')->where('id', $contract->company_id)->value('legal_name'),
            'date' => $contract->ends_on?->format('d/m/Y')];
    }

    private function tellParty(Contract $contract, string $type): void
    {
        if ($contract->provider_company_id !== null) {
            $this->notify->toCompanyPermission((int) $contract->provider_company_id, 'provider.contracts.view', $type, self::params($contract), '/provider/contracts/'.$contract->ulid);
        }
    }
}
