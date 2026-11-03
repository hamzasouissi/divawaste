<?php

namespace App\Modules\Marketplace\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Who may bid on a listing of the bourse: a provider of the platform whose profile is published and whose company is
 * active, that receives waste (collects, recycles or sorts it — a company that only transports does not buy), that
 * accepts this waste (its family, or the catalogue item of the waste type), and that holds an approved accreditation
 * valid on the day — one covering hazardous waste for a hazardous lot. It is the eligibility of a pickup receiver,
 * without the partnership: the bourse is where a company meets receivers it does not work with yet.
 *
 * Reads provider-owned tables without tenant scope on purpose, like the provider directory.
 */
final class MarketplaceEligibility
{
    /**
     * Why the provider may not bid on a waste of this type (null = it may).
     *
     * @return 'not_published'|'not_a_receiver'|'no_valid_accreditation'|'hazardous_not_covered'|'waste_not_accepted'|null
     */
    public function reason(int $providerId, int $wasteTypeId, bool $hazardous, ?Carbon $on = null): ?string
    {
        $on ??= Carbon::today();
        $profile = DB::table('provider_profiles as p')->join('companies as c', 'c.id', '=', 'p.company_id')
            ->where('p.company_id', $providerId)->where('p.is_published', true)->where('c.status', 'active')
            ->first(['p.is_collector', 'p.is_recycler', 'p.is_sorter']);
        if ($profile === null) {
            return 'not_published';
        }
        if (! $profile->is_collector && ! $profile->is_recycler && ! $profile->is_sorter) {
            return 'not_a_receiver';
        }
        $accreditations = $this->validAccreditations($providerId, $on);
        if ($accreditations->isEmpty()) {
            return 'no_valid_accreditation';
        }
        if ($hazardous && ! $accreditations->contains(fn ($row) => (bool) $row->covers_hazardous)) {
            return 'hazardous_not_covered';
        }
        $type = DB::table('waste_types')->where('id', $wasteTypeId)->first(['waste_family_id', 'waste_catalog_item_id']);
        if ($type === null || ! $this->accepts($providerId, $type->waste_family_id === null ? null : (int) $type->waste_family_id, $type->waste_catalog_item_id === null ? null : (int) $type->waste_catalog_item_id)) {
            return 'waste_not_accepted';
        }

        return null;
    }

    /**
     * What a provider may bid on: the families and catalogue items it accepts, and whether it may take hazardous waste.
     * Null when it may bid on nothing at all.
     *
     * @return array{families: list<int>, items: list<int>, hazardous: bool}|null
     */
    public function scopeOf(int $providerId, ?Carbon $on = null): ?array
    {
        $on ??= Carbon::today();
        $receives = DB::table('provider_profiles as p')->join('companies as c', 'c.id', '=', 'p.company_id')
            ->where('p.company_id', $providerId)->where('p.is_published', true)->where('c.status', 'active')
            ->where(fn ($query) => $query->where('p.is_collector', true)->orWhere('p.is_recycler', true)->orWhere('p.is_sorter', true))->exists();
        $accreditations = $this->validAccreditations($providerId, $on);
        if (! $receives || $accreditations->isEmpty()) {
            return null;
        }
        $accepted = DB::table('provider_accepted_wastes')->where('company_id', $providerId)->where('is_active', true)->get(['waste_family_id', 'waste_catalog_item_id']);

        return [
            'families' => $accepted->whereNull('waste_catalog_item_id')->pluck('waste_family_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all(),
            'items' => $accepted->pluck('waste_catalog_item_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all(),
            'hazardous' => $accreditations->contains(fn ($row) => (bool) $row->covers_hazardous),
        ];
    }

    /**
     * The providers that may bid on a waste of this type: told when a listing for them is published.
     *
     * @return Collection<int, int>
     */
    public function providersFor(int $wasteTypeId, bool $hazardous): Collection
    {
        $type = DB::table('waste_types')->where('id', $wasteTypeId)->first(['waste_family_id', 'waste_catalog_item_id']);
        if ($type === null) {
            return collect();
        }
        $candidates = DB::table('provider_accepted_wastes')->where('is_active', true)
            ->where(fn ($query) => $query->where(fn ($q) => $q->whereNull('waste_catalog_item_id')->where('waste_family_id', $type->waste_family_id))
                ->when($type->waste_catalog_item_id !== null, fn ($q) => $q->orWhere('waste_catalog_item_id', $type->waste_catalog_item_id)))
            ->distinct()->pluck('company_id');

        return $candidates->map(fn ($id) => (int) $id)->filter(fn (int $id) => $this->reason($id, $wasteTypeId, $hazardous) === null)->values();
    }

    private function accepts(int $providerId, ?int $familyId, ?int $itemId): bool
    {
        return DB::table('provider_accepted_wastes')->where('company_id', $providerId)->where('is_active', true)
            ->where(fn ($query) => $query->where(fn ($q) => $q->whereNull('waste_catalog_item_id')->where('waste_family_id', $familyId))
                ->when($itemId !== null, fn ($q) => $q->orWhere('waste_catalog_item_id', $itemId)))
            ->exists();
    }

    /**
     * @return Collection<int, \stdClass>
     */
    private function validAccreditations(int $providerId, Carbon $on): Collection
    {
        return DB::table('provider_accreditations as a')->join('accreditation_types as t', 't.id', '=', 'a.accreditation_type_id')
            ->where('a.company_id', $providerId)->where('a.review_status', 'approved')->whereNull('a.deleted_at')
            ->whereDate('a.valid_from', '<=', $on)->whereDate('a.expires_on', '>=', $on)->get(['t.covers_hazardous']);
    }
}
