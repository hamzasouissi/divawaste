<?php

namespace App\Modules\Reporting\Support;

use App\Modules\Contracts\Models\Contract;
use App\Modules\Contracts\Support\ContractTerms;
use App\Modules\Documents\Enums\HazardousWasteManifestStatus as Slip;
use App\Modules\Identity\Authorization\AccessResolver;
use App\Modules\Identity\Models\User;
use App\Modules\Marketplace\Models\MarketplaceNegotiation;
use App\Modules\Pickups\Enums\PickupStatus;
use App\Modules\Pickups\Models\Pickup;
use App\Modules\Pickups\Support\PickupCompliance;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Support\CompanyModules;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What waits for someone in the company, most urgent first: the "À traiter" of the dashboard, the "Ce qui manque" of
 * the compliance page, and what DAVI answers to "qu'est-ce que je dois faire ?". Each item counts things the user may
 * see (sites of their permissions), names the page that lists them and is worded by the interface from its key.
 * Stock limits are not here: they are computed with the stock, on the stock page.
 */
final class Attention
{
    private const SOON_DAYS = 30;

    public function __construct(private readonly AccessResolver $access) {}

    /**
     * @return list<array{key: string, area: string, tone: string, count: int, params: array<string, mixed>, link: string, query?: array<string, string>}>
     */
    public function for(User $user, Company $company): array
    {
        $items = [];
        $modules = CompanyModules::of($company);
        $today = Carbon::today();
        $sites = fn (string $permission) => $this->access->siteIdsFor($user, $permission);
        $add = function (string $key, string $area, string $tone, int $count, string $link, array $params = [], array $query = []) use (&$items): void {
            if ($count > 0) {
                $items[] = ['key' => $key, 'area' => $area, 'tone' => $tone, 'count' => $count, 'params' => ['count' => $count] + $params, 'link' => $link] + ($query ? ['query' => $query] : []);
            }
        };
        if ($company->company_type->value === 'provider') {
            $this->forProvider($user, $company, $add, $today);

            return $this->ranked($items);
        }

        // Compliance: slips to sign, signed copies, documents of pickups that left, codes, declarations, permits.
        $documentSites = $sites('documents.view');
        if ($documentSites !== []) {
            $slips = fn (Slip $status) => DB::table('hazardous_waste_manifests')->where('company_id', $company->id)->where('status', $status->value)
                ->when($documentSites !== null, fn ($query) => $query->whereIn('site_id', $documentSites))->count();
            $add('slips_to_sign', 'compliance', 'warning', $slips(Slip::PendingProducerSignature), '/compliance/manifests', [], ['status' => Slip::PendingProducerSignature->value]);
            $add('signed_copies', 'compliance', 'warning', $slips(Slip::PendingSignedCopy), '/compliance/manifests', [], ['status' => Slip::PendingSignedCopy->value]);
            $outstanding = PickupCompliance::whereOutstanding(Pickup::query())
                ->when($documentSites !== null, fn ($query) => $query->whereIn('site_id', $documentSites))->count();
            $add('documents_outstanding', 'compliance', 'warning', $outstanding, '/pickups', [], ['documents' => '1']);
        }
        if ($user->can('waste_types.view')) {
            $missing = DB::table('waste_types')->where('company_id', $company->id)->whereNull('deleted_at')->where('is_active', true)
                ->where('is_hazardous', true)->whereNull('regulatory_waste_code_id')->count();
            $add('missing_codes', 'compliance', 'danger', $missing, '/waste-types', [], ['missing' => '1']);
        }
        $declarationSites = $sites('declarations.prepare');
        if ($declarationSites !== []) {
            // Last year's declaration of every site that produced waste that year, until it is validated.
            $year = $today->year - 1;
            $producing = DB::table('waste_lots')->where('company_id', $company->id)->whereYear('generated_at', $year)
                ->when($declarationSites !== null, fn ($query) => $query->whereIn('site_id', $declarationSites))->distinct()->pluck('site_id');
            $validated = DB::table('annual_declarations')->where('company_id', $company->id)->where('reporting_year', $year)
                ->whereIn('status', ['validated', 'submitted'])->pluck('site_id');
            $add('declarations_due', 'compliance', $today->month <= 3 ? 'warning' : 'info', $producing->diff($validated)->count(), '/compliance/declarations', ['year' => $year]);
        }
        if ($modules['authorizations'] && $user->can('waste_types.view')) {
            $permits = DB::table('waste_authorizations')->where('company_id', $company->id)->where('is_active', true);
            $add('permits_expired', 'compliance', 'danger', (clone $permits)->whereDate('valid_until', '<', $today)->count(), '/compliance/authorizations');
            $add('permits_expiring', 'compliance', 'warning', (clone $permits)->whereDate('valid_until', '>=', $today)
                ->whereDate('valid_until', '<=', $today->copy()->addDays(self::SOON_DAYS))->count(), '/compliance/authorizations');
            // A type that leaves only under a customs permit, without a permit in force: none of its lots can leave.
            $covered = (clone $permits)->whereDate('valid_from', '<=', $today)->whereDate('valid_until', '>=', $today)->select('waste_type_id');
            $add('types_without_permit', 'compliance', 'warning', DB::table('waste_types')->where('company_id', $company->id)->whereNull('deleted_at')
                ->where('is_active', true)->where('requires_authorization', true)->whereNotIn('id', $covered)->count(), '/compliance/authorizations');
        }

        // Lots (davi/checks/catalogue.yaml): what keeps a pickup's price, a stock limit or a permit's weight from being
        // known. The first numbers go with the item: DAVI proposes the action on them (weigh, count, store).
        $lotSites = $sites('lots.view');
        if ($lotSites !== []) {
            $lots = fn () => DB::table('waste_lots')->where('company_id', $company->id)->whereNull('deleted_at')
                ->when($lotSites !== null, fn ($query) => $query->whereIn('site_id', $lotSites));
            $onSite = ['created', 'stored', 'awaiting_pickup'];
            $planned = $lots()->whereNull('net_weight_kg')->whereIn('id', DB::table('pickup_lots')->join('pickups', 'pickups.id', '=', 'pickup_lots.pickup_id')
                ->where('pickup_lots.company_id', $company->id)->where('pickup_lots.line_status', 'planned')
                ->whereIn('pickups.status', [PickupStatus::Requested->value, PickupStatus::Confirmed->value])->select('pickup_lots.waste_lot_id'))
                ->orderBy('generated_at')->pluck('lot_number');
            $add('lots_unweighed_in_pickup', 'lots', 'danger', $planned->count(), '/lots', ['lots' => $planned->take(5)->values()->all()], ['unweighed' => '1']);
            $long = $lots()->whereNull('net_weight_kg')->whereIn('status', $onSite)->where('generated_at', '<', $today->copy()->subDays(7))
                ->whereNotIn('lot_number', $planned)->orderBy('generated_at')->pluck('lot_number');
            $add('lots_unweighed_long', 'lots', 'warning', $long->count(), '/lots', ['lots' => $long->take(5)->values()->all()], ['unweighed' => '1']);
            $packages = $lots()->whereNotNull('packaging_type_id')->where('package_count_confirmed', false)->whereIn('status', $onSite)->orderBy('generated_at')->pluck('lot_number');
            $add('lots_packages_unconfirmed', 'lots', 'info', $packages->count(), '/lots', ['lots' => $packages->take(5)->values()->all()]);
            $created = $lots()->where('status', 'created')->where('generated_at', '<', now()->subDay())->orderBy('generated_at')->pluck('lot_number');
            $add('lots_not_stored', 'lots', 'info', $created->count(), '/lots', ['lots' => $created->take(5)->values()->all()], ['status' => 'created']);
        }

        // Pickups: gaps to handle, requests the providers have not answered, drafts never sent.
        $pickupSites = $sites('pickups.view');
        if ($pickupSites !== []) {
            $pickups = fn () => Pickup::query()->when($pickupSites !== null, fn ($query) => $query->whereIn('site_id', $pickupSites));
            $add('variances', 'pickups', 'danger', $pickups()->where('status', PickupStatus::Received)->where('variance_flagged', true)
                ->whereNull('variance_acknowledged_at')->count(), '/pickups', [], ['variance' => '1']);
            $add('awaiting_provider', 'pickups', 'info', $pickups()->where('status', PickupStatus::Requested)->count(), '/pickups', [], ['tab' => 'provider']);
            $add('drafts', 'pickups', 'neutral', $pickups()->where('status', PickupStatus::Draft)->count(), '/pickups', [], ['status' => 'draft']);
            // A confirmed date that has passed with nothing loaded: the truck did not come, or nobody recorded it.
            $add('pickups_date_passed', 'pickups', 'warning', $pickups()->where('status', PickupStatus::Confirmed)->whereDate('confirmed_date', '<', $today)->count(), '/pickups', [], ['status' => 'confirmed']);
        }

        // The bourse: offers on the company's listings that wait for its answer (accept, counter or decline).
        if ($modules['marketplace'] && $user->can('marketplace.view')) {
            $add('marketplace_offers', 'marketplace', 'warning', MarketplaceNegotiation::query()->where('status', 'awaiting_seller')->count(), '/marketplace');
        }

        // Providers: the agréments of active partners that expire, one line each, with the provider's name.
        if ($user->can('providers.view')) {
            $accreditations = DB::table('provider_accreditations as a')->join('provider_partnerships as pp', 'pp.provider_company_id', '=', 'a.company_id')
                ->join('companies as c', 'c.id', '=', 'a.company_id')
                ->where('pp.company_id', $company->id)->where('pp.status', 'active')->where('a.review_status', 'approved')
                ->whereNull('a.deleted_at')->whereNull('a.superseded_by_accreditation_id')
                ->whereDate('a.expires_on', '<=', $today->copy()->addDays(self::SOON_DAYS))
                ->orderBy('a.expires_on')->get(['c.legal_name', 'a.expires_on']);
            foreach ($accreditations as $row) {
                $days = (int) $today->diffInDays(Carbon::parse($row->expires_on), false);
                $add($days < 0 ? 'accreditation_expired' : 'accreditation_expiring', 'providers', $days < 0 ? 'danger' : 'warning', 1, '/providers',
                    ['provider' => $row->legal_name, 'days' => max(0, $days)]);
            }
        }

        // Contracts: those behind their pace, and those that end soon.
        if ($modules['contracts'] && $user->can('contracts.view')) {
            $active = Contract::query()->where('status', 'active')->whereDate('starts_on', '<=', $today)
                ->where(fn ($query) => $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $today))->get();
            $add('contracts_late', 'contracts', 'warning', $active->filter(fn (Contract $contract) => ContractTerms::progress($contract)['late'] ?? false)->count(), '/contracts');
            $add('contracts_ending', 'contracts', 'info', $active->filter(fn (Contract $contract) => $contract->ends_on !== null
                && $contract->ends_on->lte($today->copy()->addDays(self::SOON_DAYS)))->count(), '/contracts');
        }

        // ERP exports read at night: one that could not be read, or whose lines were refused.
        if ($modules['production'] && $user->can('production.manage')) {
            $failed = array_filter($company->settings['erp_feeds'] ?? [], fn (array $feed) => ($feed['enabled'] ?? false)
                && (($feed['last_error'] ?? null) !== null || ($feed['last_result']['errors'] ?? 0) > 0));
            $add('erp_feeds_failed', 'production', 'warning', count($failed), '/production/import');
        }

        // Set-up: what the company still lacks to work fully, for whoever administers it.
        if ($user->can('company.update')) {
            $add('setup_sites_without_zones', 'setup', 'info', DB::table('sites')->where('company_id', $company->id)->where('is_active', true)
                ->whereNotExists(fn ($query) => $query->from('zones')->whereColumn('zones.site_id', 'sites.id')->where('zones.is_active', true))->count(), '/sites');
            if (! DB::table('waste_types')->where('company_id', $company->id)->whereNull('deleted_at')->where('is_active', true)->exists()) {
                $add('setup_no_waste_types', 'setup', 'info', 1, '/waste-types');
            }
            if (! DB::table('stock_thresholds')->where('company_id', $company->id)->exists() && DB::table('waste_lots')->where('company_id', $company->id)->exists()) {
                $add('setup_no_stock_limit', 'setup', 'neutral', 1, '/stock');
            }
        }

        return $this->ranked($items);
    }

    /**
     * What waits for a provider: the requests to answer, the lots on their way to receive, the treatments to declare,
     * the bourse offers answered by the seller, and its own agréments (expiring, or still under review).
     *
     * @param  callable(string, string, string, int, string, array<string, mixed>=, array<string, string>=): void  $add
     */
    private function forProvider(User $user, Company $company, callable $add, Carbon $today): void
    {
        if ($user->can('provider.pickups.view')) {
            $mine = fn () => Pickup::query()->where('provider_company_id', $company->id);
            $add('provider_requests', 'pickups', 'warning', $mine()->where('status', PickupStatus::Requested)->count(), '/provider/pickups');
            $add('provider_to_receive', 'pickups', 'info', $mine()->where('status', PickupStatus::Collected)->count(), '/provider/pickups');
            $add('provider_to_treat', 'pickups', 'info', $mine()->where('status', PickupStatus::Received)->count(), '/provider/pickups');
        }
        if ($user->can('provider.marketplace.view')) {
            $add('provider_offers', 'marketplace', 'warning', MarketplaceNegotiation::query()->where('provider_company_id', $company->id)
                ->where('status', 'awaiting_buyer')->count(), '/provider/marketplace');
        }
        if ($user->can('provider.accreditations.manage')) {
            $own = fn () => DB::table('provider_accreditations')->where('company_id', $company->id)->whereNull('deleted_at');
            $expiring = $own()->where('review_status', 'approved')->whereNull('superseded_by_accreditation_id')
                ->whereDate('expires_on', '<=', $today->copy()->addDays(self::SOON_DAYS))->orderBy('expires_on')->pluck('expires_on');
            foreach ($expiring as $expiresOn) {
                $days = (int) $today->diffInDays(Carbon::parse($expiresOn), false);
                $add($days < 0 ? 'own_accreditation_expired' : 'own_accreditation_expiring', 'providers', $days < 0 ? 'danger' : 'warning', 1,
                    '/provider/accreditations', ['days' => max(0, $days)]);
            }
            $add('own_accreditation_pending', 'providers', 'info', $own()->where('review_status', 'pending')->count(), '/provider/accreditations');
        }
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function ranked(array $items): array
    {
        $rank = ['danger' => 0, 'warning' => 1, 'info' => 2, 'neutral' => 3];
        usort($items, fn (array $a, array $b) => $rank[$a['tone']] <=> $rank[$b['tone']]);

        return $items;
    }
}
