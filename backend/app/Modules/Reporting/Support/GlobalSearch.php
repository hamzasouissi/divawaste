<?php

namespace App\Modules\Reporting\Support;

use App\Modules\Contracts\Models\Contract;
use App\Modules\Documents\Models\HazardousWasteManifest;
use App\Modules\Identity\Authorization\AccessResolver;
use App\Modules\Identity\Models\User;
use App\Modules\Marketplace\Models\MarketplaceListing;
use App\Modules\Pickups\Models\Pickup;
use App\Modules\Providers\Models\ExternalPartner;
use App\Modules\Sites\Models\Site;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Waste\Models\WasteLot;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * One search box for every number and name (SH-01): a lot, a pickup, a contract, an annonce, a slip, a provider, a
 * waste type or a site, among what the user may open. Numbers match from their start ("ENL-2026-00" finds the
 * pickups of 2026), names anywhere in the word. Each kind gives a few results with the address of their page.
 */
final class GlobalSearch
{
    private const PER_KIND = 5;

    public function __construct(private readonly AccessResolver $access) {}

    /**
     * @return list<array{kind: string, ulid: string, label: string, sublabel: ?string, link: string}>
     */
    public function for(User $user, Company $company, string $query): array
    {
        $term = trim($query);
        if (mb_strlen($term) < 2) {
            return [];
        }
        $prefix = addcslashes(mb_strtoupper($term), '%_\\').'%';
        $anywhere = '%'.addcslashes($term, '%_\\').'%';
        $sites = fn (string $permission) => $this->access->siteIdsFor($user, $permission);
        $within = fn (Builder $query, ?array $ids) => $ids === null ? $query : $query->whereIn('site_id', $ids);
        $results = [];

        if (($ids = $sites('lots.view')) !== []) {
            foreach ($within(WasteLot::query(), $ids)->where('lot_number', 'like', $prefix)->with('wasteType:id,name')->orderByDesc('id')->limit(self::PER_KIND)->get() as $lot) {
                $results[] = ['kind' => 'lot', 'ulid' => $lot->ulid, 'label' => $lot->lot_number, 'sublabel' => $lot->wasteType?->name, 'link' => '/lots/'.$lot->ulid];
            }
        }
        if (($ids = $sites('pickups.view')) !== []) {
            foreach ($within(Pickup::query(), $ids)->where('pickup_number', 'like', $prefix)->orderByDesc('id')->limit(self::PER_KIND)->get(['ulid', 'pickup_number', 'requested_date']) as $pickup) {
                $results[] = ['kind' => 'pickup', 'ulid' => $pickup->ulid, 'label' => $pickup->pickup_number, 'sublabel' => $pickup->requested_date?->toDateString(), 'link' => '/pickups/'.$pickup->ulid];
            }
        }
        if ($user->can('contracts.view')) {
            foreach (Contract::query()->where('company_id', $company->id)->where(fn ($q) => $q->where('contract_number', 'like', $prefix)->orWhere('title', 'like', $anywhere)->orWhere('reference', 'like', $anywhere))
                ->orderByDesc('id')->limit(self::PER_KIND)->get(['ulid', 'contract_number', 'title']) as $contract) {
                $results[] = ['kind' => 'contract', 'ulid' => $contract->ulid, 'label' => $contract->contract_number, 'sublabel' => $contract->title, 'link' => '/contracts/'.$contract->ulid];
            }
        }
        if ($user->can('marketplace.view') || $this->access->siteIdsFor($user, 'marketplace.view') !== []) {
            foreach (MarketplaceListing::query()->where('company_id', $company->id)->where(fn ($q) => $q->where('listing_number', 'like', $prefix)->orWhere('title', 'like', $anywhere))
                ->orderByDesc('id')->limit(self::PER_KIND)->get(['ulid', 'listing_number', 'title']) as $listing) {
                $results[] = ['kind' => 'listing', 'ulid' => $listing->ulid, 'label' => $listing->listing_number, 'sublabel' => $listing->title, 'link' => '/marketplace/'.$listing->ulid];
            }
        }
        if (($ids = $sites('documents.view')) !== []) {
            foreach ($within(HazardousWasteManifest::query()->where('company_id', $company->id), $ids)->where('manifest_number', 'like', $prefix)
                ->orderByDesc('id')->limit(self::PER_KIND)->get(['ulid', 'manifest_number', 'status']) as $slip) {
                $results[] = ['kind' => 'manifest', 'ulid' => $slip->ulid, 'label' => $slip->manifest_number, 'sublabel' => null, 'link' => '/compliance/manifests'];
            }
        }
        if ($user->can('providers.view')) {
            $partners = DB::table('provider_partnerships as pp')->join('companies as c', 'c.id', '=', 'pp.provider_company_id')
                ->where('pp.company_id', $company->id)->where('pp.status', 'active')->where('c.legal_name', 'like', $anywhere)
                ->limit(self::PER_KIND)->get(['c.ulid', 'c.legal_name', 'c.city']);
            foreach ($partners as $partner) {
                $results[] = ['kind' => 'provider', 'ulid' => $partner->ulid, 'label' => $partner->legal_name, 'sublabel' => $partner->city, 'link' => '/providers'];
            }
            foreach (ExternalPartner::query()->where('legal_name', 'like', $anywhere)->limit(self::PER_KIND)->get(['ulid', 'legal_name', 'city']) as $partner) {
                $results[] = ['kind' => 'partner', 'ulid' => $partner->ulid, 'label' => $partner->legal_name, 'sublabel' => $partner->city, 'link' => '/providers'];
            }
        }
        if ($user->can('waste_types.view')) {
            $types = DB::table('waste_types')->where('company_id', $company->id)->whereNull('deleted_at')
                ->where(fn ($q) => $q->where('name', 'like', $anywhere)->orWhere('code', 'like', $prefix))->limit(self::PER_KIND)->get(['ulid', 'code', 'name']);
            foreach ($types as $type) {
                $results[] = ['kind' => 'waste_type', 'ulid' => $type->ulid, 'label' => $type->name, 'sublabel' => $type->code, 'link' => '/waste-types'];
            }
        }
        if (($ids = $sites('sites.view')) !== []) {
            $query = Site::query()->where(fn ($q) => $q->where('name', 'like', $anywhere)->orWhere('code', 'like', $prefix));
            foreach (($ids === null ? $query : $query->whereIn('id', $ids))->limit(self::PER_KIND)->get(['ulid', 'code', 'name']) as $site) {
                $results[] = ['kind' => 'site', 'ulid' => $site->ulid, 'label' => $site->name, 'sublabel' => $site->code, 'link' => '/sites/'.$site->ulid];
            }
        }

        return $results;
    }
}
