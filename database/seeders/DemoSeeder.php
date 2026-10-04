<?php

namespace Database\Seeders;

use App\Modules\Billing\Actions\StartTrial;
use App\Modules\Catalog\Actions\CreateWasteType;
use App\Modules\Catalog\DTOs\CreateWasteTypeData;
use App\Modules\Common\Support\FileStorage;
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
 */
class DemoSeeder extends Seeder
{
    public const PASSWORD = 'DivaWaste!2026';

    public function run(TenantContext $context, CreateWasteType $createType, CreateWasteLot $createLot, PickupWorkflow $pickups, StartTrial $trial, FileStorage $files): void
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

        $context->runAs($industrial, function () use ($industrial, $provider, $amira, $karim, $user, $role, $createType, $createLot, $pickups) {
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

            foreach ([2, 1, 0] as $monthsAgo) {
                foreach ($sites as $siteCode => $site) {
                    ProductionVolume::query()->create(['site_id' => $site->id, 'period_month' => now()->subMonths($monthsAgo)->startOfMonth()->toDateString(),
                        'pieces_produced' => $siteCode === 'NAB' ? 48000 - $monthsAgo * 2500 : 21000 - $monthsAgo * 1200, 'source' => 'manual', 'entered_by_user_id' => $sami->id]);
                }
            }
        });
    }
}
