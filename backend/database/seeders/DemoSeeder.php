<?php

namespace Database\Seeders;

use App\Modules\Billing\Actions\DeclareBankTransfer;
use App\Modules\Billing\Actions\ManageSubscription;
use App\Modules\Billing\Actions\StartTrial;
use App\Modules\Billing\DTOs\DeclareBankTransferData;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Catalog\Actions\CreateWasteType;
use App\Modules\Catalog\DTOs\CreateWasteTypeData;
use App\Modules\Common\Support\FileStorage;
use App\Modules\Documents\Actions\ManifestWorkflow;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\UserRoleAssignment;
use App\Modules\Pickups\Actions\PickupWorkflow;
use App\Modules\Providers\Models\ProviderAcceptedWaste;
use App\Modules\Providers\Models\ProviderAccreditation;
use App\Modules\Providers\Models\ProviderPartnership;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\Reporting\Models\ProductionVolume;
use App\Modules\Sites\Models\Site;
use App\Modules\Sites\Models\StockThreshold;
use App\Modules\Sites\Models\Zone;
use App\Modules\Tenancy\Actions\ReviewCompany;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Models\CompanyUser;
use App\Modules\Tenancy\TenantContext;
use App\Modules\Waste\Actions\CreateWasteLot;
use App\Modules\Waste\DTOs\CreateWasteLotData;
use App\Modules\Waste\Models\WasteLot;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Development and demonstration data, built through the same actions as the application so every figure shown
 * on screen comes from a real workflow (lots → pickups → reception → treatment). Never runs in production.
 *
 * Accounts (password: DivaWaste!2026):
 *   admin@divawaste.test        platform super-administrator
 *   amira@capbon.test           client administrator
 *   sami@capbon.test            environment manager (all sites)
 *   leila@capbon.test           workshop operator (Nabeul)
 *   direction@capbon.test       management, read-only
 *   audit@capbon.test           auditor
 *   karim@sahel-recyclage.test  provider administrator
 *   nour@monastir-jeans.test    client administrator of a second, unrelated industrial (tenant isolation checks)
 *   mehdi@tissages-kh.test      contact of a registration awaiting approval
 *   sonia@sfax-eco.test         contact of a provider awaiting approval
 *   walid@ecotri-tunis.test     provider whose accreditation has expired
 *   hatem@sousse-mode.test      client administrator of a suspended company
 *   rim@atelier-rades.test      contact of a rejected registration
 * The full table is in docs/TEST_ACCOUNTS.md.
 */
class DemoSeeder extends Seeder
{
    public const PASSWORD = 'DivaWaste!2026';

    /**
     * What the main company must never see, and what the platform has to decide:
     * a second industrial with its own data, a registration awaiting approval, a provider awaiting approval with an
     * accreditation to review, and a provider whose accreditation has expired.
     */
    private function otherCompanies(TenantContext $context, \Closure $company, \Closure $user, \Closure $role, Company $provider, CreateWasteType $createType,
        CreateWasteLot $createLot, StartTrial $trial, FileStorage $files): void
    {
        $member = fn (User $who, string $roleName) => [CompanyUser::query()->create(['user_id' => $who->id, 'joined_at' => now()]),
            UserRoleAssignment::query()->create(['user_id' => $who->id, 'role_id' => $role($roleName), 'site_id' => null])];
        $accreditation = fn (Company $owner, User $by, string $type, string $reference, $expires, string $status) => ProviderAccreditation::query()->create([
            'accreditation_type_id' => DB::table('accreditation_types')->where('code', $type)->value('id'), 'reference_number' => $reference, 'issuing_authority' => 'ANGed',
            'valid_from' => now()->subYears(2), 'expires_on' => $expires, 'review_status' => $status, 'reviewed_at' => $status === 'pending' ? null : now(),
            'stored_file_id' => $files->storeContent("%PDF-1.4\n% Agrément de démonstration {$reference}\n%%EOF\n", "{$reference}.pdf", 'application/pdf', $owner, 'accreditations', $by->id)->id,
        ]);

        // Second industrial: used to check that two clients never see each other's data.
        $other = $company('industrial', 'Monastir Jeans Confection', '2468135C', 'Monastir');
        $nour = $user('Nour', 'Belhadj', 'nour@monastir-jeans.test');
        $other->forceFill(['primary_contact_user_id' => $nour->id])->save();
        $trial->handle($other);
        $context->runAs($other, function () use ($other, $nour, $member, $provider, $createType, $createLot) {
            $member($nour, 'client_admin');
            $site = Site::query()->create(['code' => 'MON', 'name' => 'Unité de Monastir', 'city' => 'Monastir', 'country_id' => $other->country_id, 'activated_at' => now(), 'created_by_user_id' => $nour->id]);
            $zone = $site->zones()->create(['code' => 'DELAV', 'name' => 'Délavage', 'zone_type_id' => DB::table('zone_types')->where('code', 'dyeing')->value('id')]);
            $denim = $createType->handle(new CreateWasteTypeData(code: 'DENIM', catalogItem: 'cutting_scraps'), $nour);
            foreach ([210, 185, 240] as $index => $kg) {
                $createLot->handle(new CreateWasteLotData(siteUlid: $site->ulid, zoneUlid: $zone->ulid, wasteTypeUlid: $denim->ulid, netWeightKg: (string) $kg,
                    packagingType: 'bale', generatedAt: now()->subDays(6 - $index * 2)->setTime(10, 0)->toDateTimeString()), $nour);
            }
            ProviderPartnership::query()->create(['provider_company_id' => $provider->id, 'status' => 'active', 'started_at' => now()->subMonth(), 'created_by_user_id' => $nour->id]);
        });

        // Registration waiting for the platform's decision.
        $pending = $company('industrial', 'Tissages de Ksar Hellal', '1357924D', 'Ksar Hellal');
        $mehdi = $user('Mehdi', 'Chaabane', 'mehdi@tissages-kh.test');
        $pending->forceFill(['status' => 'pending_approval', 'approved_at' => null, 'submitted_at' => now()->subDay(), 'primary_contact_user_id' => $mehdi->id,
            'settings' => ['requested_plan' => 'per_site']])->save();
        $context->runAs($pending, fn () => $member($mehdi, 'client_admin'));

        // Provider waiting for approval, with an accreditation to review.
        $applicant = $company('provider', 'Sfax Éco-Collecte', '9753108E', 'Sfax');
        $sonia = $user('Sonia', 'Hammami', 'sonia@sfax-eco.test');
        $applicant->forceFill(['status' => 'pending_approval', 'approved_at' => null, 'submitted_at' => now()->subHours(5), 'primary_contact_user_id' => $sonia->id])->save();
        $context->runAs($applicant, function () use ($applicant, $sonia, $member, $accreditation) {
            $member($sonia, 'provider_admin');
            ProviderProfile::query()->create(['is_collector' => true, 'is_transporter' => true, 'service_area' => 'Sfax, Gabès', 'description' => 'Collecte et transport de déchets industriels banals.',
                'public_contact_name' => 'Sonia Hammami', 'public_contact_email' => $sonia->email, 'is_published' => false]);
            $accreditation($applicant, $sonia, 'recycling_recovery', 'ANGED-REC-2026-207', now()->addYears(2), 'pending');
        });

        // Published provider whose only accreditation has expired: listed in the directory, refused for a pickup.
        $expired = $company('provider', 'Éco-Tri Tunis', '8642097F', 'Tunis');
        $walid = $user('Walid', 'Dridi', 'walid@ecotri-tunis.test');
        $context->runAs($expired, function () use ($expired, $walid, $member, $accreditation) {
            $member($walid, 'provider_admin');
            ProviderProfile::query()->create(['is_collector' => true, 'is_recycler' => true, 'service_area' => 'Grand Tunis', 'description' => 'Tri et recyclage de cartons et plastiques.',
                'public_contact_name' => 'Walid Dridi', 'public_contact_email' => $walid->email, 'is_published' => true, 'published_at' => now()->subYear()]);
            $accreditation($expired, $walid, 'recycling_recovery', 'ANGED-REC-2024-031', now()->subDays(10), 'approved');
        });
    }

    /**
     * The platform's side of the story, played through the real actions so the back office has something true to
     * show: reference prices, a paying client with an invoice and a declared bank transfer, a plan request, a
     * suspended client and a rejected registration.
     */
    private function platformStory(TenantContext $context, \Closure $company, \Closure $user, \Closure $role, User $staff, StartTrial $trial): void
    {
        $member = fn (User $who) => [CompanyUser::query()->create(['user_id' => $who->id, 'joined_at' => now()]),
            UserRoleAssignment::query()->create(['user_id' => $who->id, 'role_id' => $role('client_admin'), 'site_id' => null])];

        // Reference prices (demonstration values: the real price grid is Diva Software's decision).
        foreach (['per_site' => ['per_site', '150', '1500'], 'per_volume' => ['base_fee', '400', '4000']] as $plan => [$component, $monthly, $yearly]) {
            foreach (['monthly' => $monthly, 'yearly' => $yearly] as $interval => $amount) {
                DB::table('subscription_plan_prices')->insert(['subscription_plan_id' => DB::table('subscription_plans')->where('code', $plan)->value('id'), 'currency_code' => 'TND',
                    'billing_interval' => $interval, 'component' => $component, 'unit_amount' => $amount, 'tier_min' => 0, 'valid_from' => now()->subMonths(3)->toDateString(),
                    'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        // Monastir Jeans pays: plan activated at the reference price, first invoice issued, transfer declared and waiting.
        $monastir = Company::query()->where('tax_id', '2468135C')->firstOrFail();
        $nour = User::query()->where('email', 'nour@monastir-jeans.test')->firstOrFail();
        app(ManageSubscription::class)->activate($monastir, ['plan' => 'per_site', 'billing_interval' => 'monthly', 'sites_quantity' => 1, 'unit_amount' => '150'], $staff);
        $context->runAs($monastir, function () use ($nour) {
            $invoice = Invoice::query()->latest('id')->firstOrFail();
            app(DeclareBankTransfer::class)->handle($invoice, new DeclareBankTransferData((string) $invoice->total_amount, 'VIR-BIAT-2026-10-0412', now()->subDay()->toDateString()), $nour);
        });

        // Textile du Cap Bon is on trial and has asked for the per-site formula for its two sites.
        $capBon = Company::query()->where('tax_id', '1234567A')->firstOrFail();
        $capBon->forceFill(['settings' => ['requested_plan' => 'per_site', 'requested_sites' => 2] + ($capBon->settings ?? [])])->save();

        // A client suspended for non-payment, and a registration rejected for an invalid tax identifier.
        $suspended = $company('industrial', 'Sousse Mode Confection', '3692581G', 'Sousse');
        $hatem = $user('Hatem', 'Ben Amor', 'hatem@sousse-mode.test');
        $suspended->forceFill(['primary_contact_user_id' => $hatem->id, 'submitted_at' => now()->subMonths(2), 'approved_at' => now()->subMonths(2)])->save();
        $trial->handle($suspended);
        $context->runAs($suspended, fn () => $member($hatem));
        app(ReviewCompany::class)->suspend($suspended, 'Deux factures impayées malgré relances. Accès rétabli dès réception du règlement.', $staff);

        $rejected = $company('industrial', 'Atelier Textile de Radès', '0000000Z', 'Radès');
        $rim = $user('Rim', 'Gharsallah', 'rim@atelier-rades.test');
        $rejected->forceFill(['status' => 'pending_approval', 'approved_at' => null, 'submitted_at' => now()->subDays(3), 'primary_contact_user_id' => $rim->id])->save();
        $context->runAs($rejected, fn () => $member($rim));
        app(ReviewCompany::class)->reject($rejected, 'Matricule fiscal introuvable au registre national des entreprises. Merci de vous réinscrire avec le matricule exact.', $staff);
    }

    public function run(TenantContext $context, CreateWasteType $createType, CreateWasteLot $createLot, PickupWorkflow $pickups, StartTrial $trial, FileStorage $files, ManifestWorkflow $manifests): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('DemoSeeder must never run in production.');
        }
        if (Company::query()->where('tax_id', '1234567A')->exists()) {
            return;
        }

        $tn = DB::table('countries')->where('iso2', 'TN')->first(['id', 'currency_code', 'default_timezone']);
        $company = fn (string $type, string $name, string $taxId, string $city) => tap(new Company([
            'company_type' => $type, 'legal_name' => $name, 'tax_id' => $taxId, 'country_id' => $tn->id, 'currency_code' => $tn->currency_code,
            'timezone' => $tn->default_timezone, 'city' => $city, 'address_line1' => 'Zone industrielle', 'phone' => '+216 70 000 000',
        ]), fn (Company $c) => $c->forceFill(['status' => 'active', 'approved_at' => now(), 'submitted_at' => now()])->save());
        $user = fn (string $first, string $last, string $email) => tap(User::query()->create([
            'first_name' => $first, 'last_name' => $last, 'email' => $email, 'password' => self::PASSWORD, 'phone' => '+216 20 000 000',
        ]), fn (User $u) => $u->forceFill(['email_verified_at' => now()])->save());
        $role = fn (string $name) => (int) Role::query()->whereNull('company_id')->where('name', $name)->value('id');

        $staff = $user('Admin', 'Plateforme', 'admin@divawaste.test');
        $staff->assignRole('super_admin');

        // Provider with a valid accreditation.
        $provider = $company('provider', 'Recyclage du Sahel', '7654321B', 'Sousse');
        $karim = $user('Karim', 'Ben Salah', 'karim@sahel-recyclage.test');
        $context->runAs($provider, function () use ($provider, $karim, $role, $files) {
            CompanyUser::query()->create(['user_id' => $karim->id, 'joined_at' => now()]);
            UserRoleAssignment::query()->create(['user_id' => $karim->id, 'role_id' => $role('provider_admin'), 'site_id' => null]);
            ProviderProfile::query()->create(['is_collector' => true, 'is_recycler' => true, 'is_eliminator' => true, 'service_area' => 'Sousse, Monastir, Nabeul, Tunis',
                'description' => 'Collecte et recyclage de chutes textiles, cartons et plastiques ; traitement de déchets dangereux.',
                'public_contact_name' => 'Karim Ben Salah', 'public_contact_email' => $karim->email, 'public_contact_phone' => '+216 73 000 000',
                'is_published' => true, 'published_at' => now()]);
            foreach (['recycling' => 'textile_product', 'landfill' => 'packaging', 'other_treatment' => 'chemical_sludge'] as $channel => $family) {
                ProviderAcceptedWaste::query()->create(['treatment_channel_id' => DB::table('treatment_channels')->where('code', $channel)->value('id'),
                    'waste_family_id' => DB::table('waste_families')->where('code', $family)->value('id')]);
            }
            foreach (['recycling_recovery' => 'ANGED-REC-2025-118', 'hazardous_treatment' => 'ANGED-DD-2025-042'] as $type => $reference) {
                $file = $files->storeContent("%PDF-1.4\n% Agrément de démonstration {$reference}\n%%EOF\n", "{$reference}.pdf", 'application/pdf', $provider, 'accreditations', $karim->id);
                ProviderAccreditation::query()->create(['accreditation_type_id' => DB::table('accreditation_types')->where('code', $type)->value('id'),
                    'reference_number' => $reference, 'issuing_authority' => 'ANGed', 'valid_from' => now()->subYear(), 'stored_file_id' => $file->id,
                    'expires_on' => $type === 'recycling_recovery' ? now()->addDays(20) : now()->addYear(), 'review_status' => 'approved', 'reviewed_at' => now()]);
            }
        });

        // Industrial with two sites.
        $industrial = $company('industrial', 'Textile du Cap Bon', '1234567A', 'Nabeul');
        $amira = $user('Amira', 'Trabelsi', 'amira@capbon.test');
        $industrial->forceFill(['primary_contact_user_id' => $amira->id])->save();
        $trial->handle($industrial);

        $this->otherCompanies($context, $company, $user, $role, $provider, $createType, $createLot, $trial, $files);

        $context->runAs($industrial, function () use ($context, $industrial, $provider, $amira, $karim, $user, $role, $createType, $createLot, $pickups, $manifests) {
            DB::table('company_textile_activities')->insert(DB::table('textile_activities')->whereIn('code', ['weaving', 'garment_making', 'dyeing'])->pluck('id')
                ->map(fn ($id) => ['company_id' => $industrial->id, 'textile_activity_id' => $id, 'created_at' => now()])->all());

            $sites = [];
            foreach (['NAB' => ['Usine de Nabeul', 'Nabeul'], 'KOR' => ['Atelier de Korba', 'Korba']] as $code => [$name, $city]) {
                $site = Site::query()->create(['code' => $code, 'name' => $name, 'city' => $city, 'country_id' => $industrial->country_id, 'activated_at' => now(), 'created_by_user_id' => $amira->id]);
                foreach (['COUPE' => ['Atelier de coupe', 'cutting'], 'CONF' => ['Confection', 'sewing'], 'TEINT' => ['Teinture', 'dyeing']] as $zoneCode => [$zoneName, $type]) {
                    $site->zones()->create(['code' => $zoneCode, 'name' => $zoneName, 'zone_type_id' => DB::table('zone_types')->where('code', $type)->value('id')]);
                }
                Zone::query()->where('site_id', $site->id)->where('code', 'STOCK')->update(['capacity_kg' => 2000]);
                $sites[$code] = $site;
            }

            $members = [
                [$amira, 'client_admin', null], [$user('Sami', 'Gharbi', 'sami@capbon.test'), 'environment_manager', null],
                [$user('Leila', 'Mansour', 'leila@capbon.test'), 'workshop_operator', $sites['NAB']],
                [$user('Hedi', 'Jaziri', 'direction@capbon.test'), 'management_viewer', null], [$user('Nadia', 'Khelifi', 'audit@capbon.test'), 'auditor', null],
            ];
            foreach ($members as [$member, $roleName, $site]) {
                CompanyUser::query()->create(['user_id' => $member->id, 'joined_at' => now()]);
                UserRoleAssignment::query()->create(['user_id' => $member->id, 'role_id' => $role($roleName), 'site_id' => $site?->id]);
            }
            $sami = $members[1][0];

            $types = [];
            foreach (['cutting_scraps' => 'CHUTES', 'yarn_waste' => 'FILS', 'garment_rejects' => 'REBUTS', 'cardboard' => 'CARTON', 'plastic_films_bags' => 'PLAST', 'dye_residues' => 'TEINT'] as $item => $code) {
                $types[$code] = $createType->handle(new CreateWasteTypeData(code: $code, catalogItem: $item,
                    composition: $code === 'CHUTES' ? [['material' => 'CO', 'pct' => '65.00'], ['material' => 'PES', 'pct' => '35.00']] : null), $amira);
            }
            ProviderPartnership::query()->create(['provider_company_id' => $provider->id, 'status' => 'active', 'started_at' => now()->subMonths(4), 'created_by_user_id' => $amira->id]);
            StockThreshold::query()->create(['site_id' => $sites['NAB']->id, 'waste_type_id' => $types['CHUTES']->id, 'max_quantity_kg' => 900, 'warning_pct' => 80, 'created_by_user_id' => $sami->id]);

            // Three months of production: a deterministic spread so charts are stable from one seed to the next.
            $weights = ['CHUTES' => [118, 96, 142, 87, 131, 104], 'FILS' => [22, 18, 27], 'REBUTS' => [41, 36], 'CARTON' => [64, 58, 71], 'PLAST' => [19, 24], 'TEINT' => [33, 28]];
            $zoneFor = ['CHUTES' => 'COUPE', 'FILS' => 'CONF', 'REBUTS' => 'CONF', 'CARTON' => 'STOCK', 'PLAST' => 'STOCK', 'TEINT' => 'TEINT'];
            $lots = [];
            foreach ([2, 1, 0] as $monthsAgo) {
                foreach ($sites as $siteCode => $site) {
                    foreach ($weights as $typeCode => $series) {
                        foreach ($series as $index => $kg) {
                            if ($siteCode === 'KOR' && $index % 2 === 1) {
                                continue;
                            }
                            $day = min(27, 2 + $index * 4 + ($siteCode === 'KOR' ? 1 : 0));
                            $at = now()->subMonths($monthsAgo)->startOfMonth()->addDays($day)->setTime(9 + $index, 15);
                            if ($at->isFuture()) {
                                continue;
                            }
                            $zone = Zone::query()->where('site_id', $site->id)->where('code', $zoneFor[$typeCode])->firstOrFail();
                            $lot = $createLot->handle(new CreateWasteLotData(siteUlid: $site->ulid, zoneUlid: $zone->ulid, wasteTypeUlid: $types[$typeCode]->ulid,
                                netWeightKg: (string) ($kg + $monthsAgo * 3), packagingType: $typeCode === 'TEINT' ? 'drum' : 'bale', generatedAt: $at->toDateTimeString()), $sami);
                            $lots[$monthsAgo][$siteCode][] = $lot;
                        }
                    }
                }
            }

            // The two past months were evacuated, received and treated by the provider; the current month is in stock.
            $channel = ['CHUTES' => 'recycling', 'FILS' => 'recycling', 'REBUTS' => 'reuse', 'CARTON' => 'recycling', 'PLAST' => 'landfill', 'TEINT' => 'other_treatment'];
            $typeCode = array_flip(array_map(fn ($type) => $type->id, $types));
            foreach ([2, 1] as $monthsAgo) {
                foreach ($sites as $siteCode => $site) {
                    $batch = $lots[$monthsAgo][$siteCode] ?? [];
                    if ($batch === []) {
                        continue;
                    }
                    $date = now()->subMonths($monthsAgo)->endOfMonth()->subDays(2);
                    $pickup = $pickups->create($site->id, $provider->id, null, $date->toDateString(), array_map(fn (WasteLot $lot) => $lot->ulid, $batch), $sami);
                    $pickup = $pickups->submit($pickup, $sami);
                    $pickup = $pickups->confirm($pickup, $date->toDateString(), $karim);
                    $pickup = $pickups->collect($pickup, array_fill_keys(array_map(fn (WasteLot $lot) => $lot->ulid, $batch), null), $sami);
                    $received = [];
                    $treated = [];
                    $prices = [];
                    foreach ($batch as $index => $lot) {
                        $code = $typeCode[$lot->waste_type_id];
                        // One line in ten comes back 7 % lighter to show the discrepancy alert.
                        $received[$lot->lot_number] = bcmul((string) $lot->net_weight_kg, $index % 10 === 3 ? '0.93' : '0.99', 3);
                        $treated[$lot->lot_number] = (int) DB::table('treatment_channels')->where('code', $channel[$code])->value('id');
                        $prices[$lot->lot_number] = in_array($code, ['CHUTES', 'FILS', 'CARTON', 'REBUTS'], true)
                            ? ['direction' => 'sale', 'pricing_mode' => 'per_kg', 'unit_price' => $code === 'CHUTES' ? '0.350' : '0.120']
                            : ['direction' => 'cost', 'pricing_mode' => 'per_kg', 'unit_price' => $code === 'TEINT' ? '1.800' : '0.250'];
                    }
                    $pickup = $pickups->receive($pickup, $received, $karim);
                    $pickups->price($pickup, $prices, $sami);
                    $pickups->treat($pickup, $treated, $karim);
                    // Dye residues are hazardous: their tracking slip is signed by the three parties and archived.
                    foreach ($manifests->generate($pickup->refresh(), $sami) as $manifest) {
                        $manifests->sign($manifest, $sami, request());
                        $context->runAs($provider, function () use ($manifests, $manifest, $karim) {
                            $manifests->sign($manifest->refresh(), $karim, request());
                            $manifests->sign($manifest->refresh(), $karim, request());
                        });
                    }
                    if ($pickup->variance_flagged) {
                        $pickups->acknowledgeVariance($pickup, 'Écart dû à l\'humidité des chutes à la pesée de départ.', $sami);
                    }
                    if ($monthsAgo === 2) {
                        $pickups->complete($pickup->refresh(), $sami);
                    }
                    DB::table('pickups')->where('id', $pickup->id)->update(['collected_at' => $date, 'received_at' => $date->copy()->addDay(), 'submitted_at' => $date->copy()->subDays(5)]);
                }
            }

            // A request waiting for the provider's answer.
            $pending = array_slice($lots[0]['NAB'] ?? [], 0, 4);
            if ($pending !== []) {
                $pickups->submit($pickups->create($sites['NAB']->id, $provider->id, null, now()->addDays(3)->toDateString(), array_map(fn (WasteLot $lot) => $lot->ulid, $pending), $sami), $sami);
            }

            // A pickup confirmed for today, ready to be loaded from the workshop application, with its slip awaiting the producer's signature.
            $ready = [];
            foreach (['TEINT' => ['TEINT', '36.5'], 'CHUTES' => ['COUPE', '120'], 'CARTON' => ['STOCK', '55']] as $typeCode => [$zoneCode, $kg]) {
                $zone = Zone::query()->where('site_id', $sites['NAB']->id)->where('code', $zoneCode)->firstOrFail();
                $ready[] = $createLot->handle(new CreateWasteLotData(siteUlid: $sites['NAB']->ulid, zoneUlid: $zone->ulid, wasteTypeUlid: $types[$typeCode]->ulid,
                    netWeightKg: $kg, packagingType: $typeCode === 'TEINT' ? 'drum' : 'bale', generatedAt: now()->subDay()->setTime(14, 30)->toDateTimeString()), $sami)->ulid;
            }
            $confirmed = $pickups->confirm($pickups->submit($pickups->create($sites['NAB']->id, $provider->id, null, now()->toDateString(), $ready, $sami), $sami), now()->toDateString(), $karim);
            $manifests->generate($confirmed, $sami);

            foreach ([2, 1, 0] as $monthsAgo) {
                foreach ($sites as $siteCode => $site) {
                    ProductionVolume::query()->create(['site_id' => $site->id, 'period_month' => now()->subMonths($monthsAgo)->startOfMonth()->toDateString(),
                        'pieces_produced' => $siteCode === 'NAB' ? 48000 - $monthsAgo * 2500 : 21000 - $monthsAgo * 1200, 'source' => 'manual', 'entered_by_user_id' => $sami->id]);
                }
            }
        });

        $this->platformStory($context, $company, $user, $role, $staff, $trial);
    }
}
