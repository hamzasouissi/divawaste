<?php

namespace Tests\Feature\Pickups;

use App\Modules\Catalog\Models\WasteType;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\UserRoleAssignment;
use App\Modules\Notifications\BusinessEventNotification;
use App\Modules\Providers\Models\ProviderAccreditation;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\Sites\Models\Site;
use App\Modules\Sites\Models\Zone;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Models\CompanyUser;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * The decisions taken after the acceptance audit: provider minimums judged again on what was loaded, requirements of
 * one waste type, documents followed apart from the closure of a pickup, capacity emails for those who manage the
 * stock, and hazardous waste types that cannot leave without their regulatory code.
 */
class AcceptanceDecisionsTest extends TestCase
{
    use InteractsWithTenants;
    use RefreshDatabase;

    private Company $industrial;

    private Company $provider;

    private Site $site;

    private Zone $zone;

    private User $manager;

    private User $providerUser;

    private string $token;

    private string $providerToken;

    private string $adminToken;

    private string $requesterToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([ReferenceDataSeeder::class, AuthorizationSeeder::class]);
        $this->industrial = $this->makeCompany();
        $this->provider = $this->makeCompany('provider');
        $this->site = $this->makeSite($this->industrial, 'SFX');
        $this->manager = $this->makeMember($this->industrial, ['environment_manager' => $this->site]);
        $this->token = $this->tokenFor($this->manager, $this->industrial);
        $this->providerUser = $this->makeMember($this->provider, ['provider_admin' => null]);
        $this->providerToken = $this->tokenFor($this->providerUser, $this->provider);
        $this->zone = $this->tenant()->runAs($this->industrial, fn () => Zone::query()->create(['code' => 'AIRE', 'site_id' => $this->site->id,
            'zone_type_id' => DB::table('zone_types')->value('id'), 'name' => 'Aire']));
        $this->tenant()->runAs($this->provider, function () {
            ProviderProfile::query()->create(['is_recycler' => true, 'is_transporter' => true, 'is_published' => true, 'published_at' => now()]);
            $file = DB::table('stored_files')->insertGetId(['ulid' => strtoupper((string) Str::ulid()), 'company_id' => $this->provider->id, 'disk' => 's3',
                'path' => 'tenants/p/agrement.pdf', 'original_name' => 'agrement.pdf', 'mime_type' => 'application/pdf', 'extension' => 'pdf',
                'size_bytes' => 1, 'checksum_sha256' => str_repeat('a', 64), 'purpose' => 'accreditation', 'created_at' => now(), 'updated_at' => now()]);
            $type = DB::table('accreditation_types')->insertGetId(['country_id' => DB::table('countries')->value('id'), 'code' => 'TN_COLLECT', 'name' => '{}']);
            ProviderAccreditation::query()->create(['accreditation_type_id' => $type, 'reference_number' => 'AG-1', 'valid_from' => now()->subYear(),
                'expires_on' => now()->addYear(), 'stored_file_id' => $file, 'review_status' => 'approved']);
        });
        $this->adminToken = $this->tokenFor($this->makeMember($this->industrial, ['client_admin' => null]), $this->industrial);
        // A role of the company's own that requests pickups without the right to send one against a provider's requirement.
        $role = DB::table('roles')->insertGetId(['company_id' => $this->industrial->id, 'name' => 'requester', 'guard_name' => 'web', 'label' => '{"fr":"Demandeur"}',
            'audience' => 'industrial', 'is_system' => false, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('role_has_permissions')->insert(DB::table('permissions')->whereIn('name', ['app.web.access', 'lots.view', 'pickups.view', 'pickups.request'])
            ->pluck('id')->map(fn ($id) => ['permission_id' => $id, 'role_id' => $role])->all());
        $requester = User::factory()->create();
        $this->tenant()->runAs($this->industrial, function () use ($requester, $role) {
            CompanyUser::query()->create(['user_id' => $requester->id, 'joined_at' => now()]);
            UserRoleAssignment::query()->create(['user_id' => $requester->id, 'role_id' => $role, 'site_id' => null]);
        });
        $this->requesterToken = $this->tokenFor($requester, $this->industrial);
        $this->fresh($this->adminToken)->postJson('/api/v1/provider-partnerships', ['provider' => $this->provider->ulid])->assertCreated();
    }

    public function test_a_minimum_met_at_request_is_judged_again_on_what_left_and_the_receiver_is_told_once(): void
    {
        $type = $this->type('FILS', 'textile_fibre');
        $this->accepts([['channel' => 'recycling', 'family' => 'textile_fibre', 'min_quantity' => '2', 'min_unit' => 't']]);
        [$a, $b] = [$this->lotData($type, '1200'), $this->lotData($type, '1000')];
        $pickup = $this->pickup([$a['ulid'], $b['ulid']]);
        $requested = $this->api()->postJson("/api/v1/pickups/{$pickup}/submit")->assertOk()->assertJsonPath('data.provider_requirements.outcome', 'respected')->json('data.provider_requirements');
        $this->fresh($this->providerToken)->postJson("/api/v1/pickups/{$pickup}/confirm", ['confirmed_date' => now()->addDay()->toDateString()])->assertOk();

        // One of the two lots stays on site: 1,2 t leave for a minimum of 2 t.
        Notification::fake();
        $loaded = $this->api()->postJson("/api/v1/pickups/{$pickup}/collect", ['lots' => [['lot' => $a['ulid']]]])->assertOk()
            ->assertJsonPath('data.status', 'collected')->json('data');
        // The request-time answer is the one the provider confirmed: it is not rewritten.
        $this->assertEquals($requested, $loaded['provider_requirements']);
        $atLoading = $loaded['loading_requirements'];
        $this->assertSame(['warning', ['minimum:1'], [], null, [$b['lot_number']]], [$atLoading['outcome'], $atLoading['unmet'], $atLoading['failing'], $atLoading['exception'], $atLoading['left_behind']]);
        $minimum = $atLoading['constraints'][0];
        $this->assertSame(['t', '1.200', '2.000', '0.800', 1, [$b['lot_number']], 'respected', '2.200'],
            [$minimum['unit'], $minimum['used'], $minimum['min'], $minimum['shortfall'], $minimum['lots'], $minimum['left_lots'], $minimum['at_request']['outcome'], $minimum['at_request']['used']]);

        // The receiver is told what left and what it asked, in its units, in both languages — one message for the loading.
        $sent = Notification::sent($this->providerUser, BusinessEventNotification::class);
        $this->assertSame(['pickup.requirements_unmet_at_loading'], $sent->map(fn (BusinessEventNotification $n) => $n->type)->values()->all());
        $params = $sent->first()->params;
        $this->assertSame(['minimum 2 t : 1,2 t chargé(s), il manque 0,8 t', 'minimum 2 t: 1.2 t loaded, 0.8 t short', 1, '1 200 kg'],
            [$params['requirements'], $params['requirements_en'], $params['left'], $params['quantity']]);
        Notification::assertSentTo($this->providerUser, BusinessEventNotification::class, fn (BusinessEventNotification $n, array $channels) => in_array('mail', $channels, true));
        // A warning is no exception: nobody at the industrial is asked to explain anything.
        Notification::assertNotSentTo($this->manager, BusinessEventNotification::class, fn (BusinessEventNotification $n) => $n->type === 'pickup.requirement_exception');
        // What did reach them is the issued slip waiting for their signature.
        $this->assertSame(['document.signature_requested'], Notification::sent($this->manager, BusinessEventNotification::class)->map(fn ($n) => $n->type)->values()->all());

        // The loading sent again is refused before anything is judged or sent twice.
        $this->api()->postJson("/api/v1/pickups/{$pickup}/collect", ['lots' => [['lot' => $a['ulid']]]])->assertStatus(409)->assertJsonPath('code', 'INVALID_PICKUP_TRANSITION');
        $this->assertCount(1, Notification::sent($this->providerUser, BusinessEventNotification::class));
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'requirements_checked_at_loading')->count());

        // A loading on which everything asked is still met tells the receiver the plain loading.
        $pickup = $this->pickup([$this->lot($type, '2100')]);
        $this->api()->postJson("/api/v1/pickups/{$pickup}/submit")->assertOk();
        $this->fresh($this->providerToken)->postJson("/api/v1/pickups/{$pickup}/confirm", ['confirmed_date' => now()->addDay()->toDateString()])->assertOk();
        Notification::fake();
        $lot = DB::table('pickup_lots')->join('pickups', 'pickups.id', '=', 'pickup_lots.pickup_id')->where('pickups.ulid', $pickup)
            ->join('waste_lots', 'waste_lots.id', '=', 'pickup_lots.waste_lot_id')->value('waste_lots.ulid');
        $this->api()->postJson("/api/v1/pickups/{$pickup}/collect", ['lots' => [['lot' => $lot]]])->assertOk()
            ->assertJsonPath('data.loading_requirements.outcome', 'respected')->assertJsonPath('data.loading_requirements.unmet', []);
        $this->assertSame(['pickup.collected'], Notification::sent($this->providerUser, BusinessEventNotification::class)->map(fn ($n) => $n->type)->values()->all());
    }

    public function test_a_blocking_minimum_no_longer_met_at_loading_is_an_exception_someone_explains_never_a_refusal_to_leave(): void
    {
        $type = $this->type('FILS', 'textile_fibre');
        $this->accepts([['channel' => 'recycling', 'family' => 'textile_fibre', 'min_quantity' => '2', 'min_unit' => 't', 'is_blocking' => true]]);
        [$a, $b] = [$this->lot($type, '1200'), $this->lot($type, '1000')];
        $pickup = $this->pickup([$a, $b]);
        $this->api()->postJson("/api/v1/pickups/{$pickup}/submit")->assertOk()->assertJsonPath('data.provider_requirements.blocked', false);
        $this->fresh($this->providerToken)->postJson("/api/v1/pickups/{$pickup}/confirm", ['confirmed_date' => now()->addDay()->toDateString()])->assertOk();

        Notification::fake();
        // The truck is loaded: the loading is recorded, whatever the requirement says.
        $this->api()->postJson("/api/v1/pickups/{$pickup}/collect", ['lots' => [['lot' => $a]]])->assertOk()->assertJsonPath('data.status', 'collected')
            ->assertJsonPath('data.loading_requirements.outcome', 'exceeded')->assertJsonPath('data.loading_requirements.failing', ['minimum:1'])
            ->assertJsonPath('data.loading_requirements.exception', ['failing' => ['minimum:1'], 'acknowledged' => null]);
        // Those who may send a pickup against a blocking requirement are asked to say why it left under it.
        Notification::assertSentTo($this->manager, BusinessEventNotification::class, fn (BusinessEventNotification $n) => $n->type === 'pickup.requirement_exception'
            && $n->params['requirements'] === 'minimum 2 t : 1,2 t chargé(s), il manque 0,8 t');

        // Someone who may only request pickups cannot explain it; an explanation needs a reason; it is kept once, with its author.
        $this->fresh($this->requesterToken)->postJson("/api/v1/pickups/{$pickup}/loading-requirements/acknowledge", ['reason' => 'Camion plein'])->assertForbidden();
        $this->api()->postJson("/api/v1/pickups/{$pickup}/loading-requirements/acknowledge", [])->assertStatus(422);
        $this->api()->postJson("/api/v1/pickups/{$pickup}/loading-requirements/acknowledge", ['reason' => 'Le second lot part la semaine prochaine, accord du recycleur par téléphone'])->assertOk()
            ->assertJsonPath('data.loading_requirements.exception.acknowledged.reason', 'Le second lot part la semaine prochaine, accord du recycleur par téléphone')
            ->assertJsonPath('data.loading_requirements.exception.acknowledged.by', trim("{$this->manager->first_name} {$this->manager->last_name}"));
        $this->api()->postJson("/api/v1/pickups/{$pickup}/loading-requirements/acknowledge", ['reason' => 'encore'])->assertStatus(409)->assertJsonPath('code', 'NO_REQUIREMENT_EXCEPTION');
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'requirement_exception_acknowledged')->count());
        // The receiver reads the same answer and the explanation.
        $this->fresh($this->providerToken)->getJson("/api/v1/pickups/{$pickup}")->assertOk()->assertJsonPath('data.loading_requirements.exception.acknowledged.reason', 'Le second lot part la semaine prochaine, accord du recycleur par téléphone');
    }

    public function test_a_requirement_of_one_waste_type_replaces_the_family_default_for_its_lots_unless_it_adds_to_it(): void
    {
        $yarn = $this->type('FILS', 'textile_fibre', 'kg', 'yarn_waste');
        $fibre = $this->type('FIBRES', 'textile_fibre');
        $family = ['channel' => 'recycling', 'family' => 'textile_fibre', 'min_quantity' => '2', 'min_unit' => 't'];
        $item = ['channel' => 'recycling', 'family' => 'textile_fibre', 'catalog_item' => 'yarn_waste', 'min_quantity' => '500', 'min_unit' => 'kg'];
        $this->accepts([$family, $item])->assertJsonPath('data.accepted_wastes.1.catalog_item', 'yarn_waste')->assertJsonPath('data.accepted_wastes.1.adds_to_family', false);
        [$y, $f] = [$this->lotData($yarn, '600'), $this->lotData($fibre, '300')];

        // The yarn is judged on its own minimum, and the family minimum on the other lots only.
        $check = $this->check([$y['ulid'], $f['ulid']]);
        [$familyAnswer, $itemAnswer] = [$check['constraints'][0], $check['constraints'][1]];
        $this->assertSame(['family', 1, '0.300', [$y['lot_number']], 'warning'], [$familyAnswer['rule']['scope'], $familyAnswer['lots'], $familyAnswer['used'], $familyAnswer['own_rule_lots'], $familyAnswer['outcome']]);
        $this->assertSame(['type', 'yarn_waste', 1, '600.000', 'respected'], [$itemAnswer['rule']['scope'], $itemAnswer['rule']['catalog_item'], $itemAnswer['lots'], $itemAnswer['used'], $itemAnswer['outcome']]);

        // Said to come in addition to the family, the yarn counts in both.
        $this->accepts([$family, $item + ['adds_to_family' => true]]);
        $check = $this->check([$y['ulid'], $f['ulid']]);
        $this->assertSame([2, '0.900', []], [$check['constraints'][0]['lots'], $check['constraints'][0]['used'], $check['constraints'][0]['own_rule_lots']]);

        // The directory shows the type and whether it adds to the family.
        $listed = collect($this->fresh($this->adminToken)->getJson('/api/v1/providers/directory')->assertOk()->json('data'))->firstWhere('ulid', $this->provider->ulid)['accepted_wastes'];
        $this->assertSame(['yarn_waste', true], [$listed[1]['catalog_item'], $listed[1]['adds_to_family']]);

        // The same minimum twice for one waste is refused — a weight in kilograms and one in tonnes are the same question;
        // a weight and a number of bales are two. A type of another family is refused too.
        $this->fresh($this->providerToken)->putJson('/api/v1/provider/profile', ['roles' => ['recycler'], 'accepted_wastes' => [$family, ['min_quantity' => '800', 'min_unit' => 'kg'] + $family]])
            ->assertStatus(422)->assertJsonValidationErrors(['accepted_wastes.1']);
        $this->accepts([$family, ['min_quantity' => '10', 'min_unit' => 'package', 'min_packaging_type' => 'bale'] + $family]);
        $this->fresh($this->providerToken)->putJson('/api/v1/provider/profile', ['roles' => ['recycler'], 'accepted_wastes' => [['catalog_item' => 'cutting_scraps'] + $item]])
            ->assertStatus(422)->assertJsonValidationErrors(['accepted_wastes.0.catalog_item']);
    }

    public function test_the_company_names_one_of_its_own_waste_types_in_what_a_partner_without_an_account_asks(): void
    {
        $partner = $this->fresh($this->adminToken)->postJson('/api/v1/external-partners', ['legal_name' => 'Fibres du Cap', 'roles' => ['recycler', 'transporter']])->assertCreated()->json('data.ulid');
        $cones = $this->type('CONES', 'textile_fibre', 'piece');
        $fibre = $this->type('FIBRES', 'textile_fibre');
        $other = $this->type('CARTONS', 'packaging');
        $family = ['family' => 'textile_fibre', 'channel' => 'recycling', 'min_quantity' => '1', 'min_unit' => 't', 'contract_reference' => 'Contrat 2026-14'];
        $own = ['family' => 'textile_fibre', 'waste_type' => $cones, 'channel' => 'recycling', 'min_quantity' => '200', 'min_unit' => 'piece'];
        $put = fn (array $lines) => $this->fresh($this->adminToken)->putJson("/api/v1/external-partners/{$partner}/requirements", ['requirements' => $lines]);

        // Cones are counted in pieces and have no density: a minimum in m³ could never be verified. A type of another family is not this family's.
        $put([$family, ['min_unit' => 'm3', 'min_quantity' => '2'] + $own])->assertStatus(422)->assertJsonValidationErrors(['requirements.1.min_unit']);
        $put([$family, ['waste_type' => $other] + $own])->assertStatus(422)->assertJsonValidationErrors(['requirements.1.waste_type']);
        $put([$family, $family])->assertStatus(422)->assertJsonValidationErrors(['requirements.1']);
        $saved = $put([$family, $own])->assertOk()->json('data.requirements');
        $this->assertSame([null, 'CONES', false, 'Contrat 2026-14'], [$saved[0]['waste_type'], $saved[1]['waste_type']['code'], $saved[1]['adds_to_family'], $saved[0]['contract_reference']]);
        $this->assertTrue(DB::table('audit_logs')->where('auditable_type', 'partner_requirements')->where('event', 'created')->where('new_values', 'like', '%waste_type_id%')->exists());

        // 150 cones and 1,2 t of fibre: the cones are judged on their own minimum in pieces, the fibre on the family's in tonnes.
        $cone = $this->lotData($cones, null, ['quantity' => '150', 'unit' => 'piece']);
        $fibreLot = $this->lotData($fibre, '1200');
        $check = $this->api()->postJson('/api/v1/provider-requirements/check', ['external_provider' => $partner, 'lots' => [$cone['ulid'], $fibreLot['ulid']]])->assertOk()->json('data');
        $this->assertSame(['respected', 1, [$cone['lot_number']], 'internal'], [$check['constraints'][0]['outcome'], $check['constraints'][0]['lots'], $check['constraints'][0]['own_rule_lots'], $check['constraints'][0]['source']]);
        $this->assertSame(['piece', '150.000', '200.000', 'warning', 'CONES'], [$check['constraints'][1]['unit'], $check['constraints'][1]['used'], $check['constraints'][1]['min'], $check['constraints'][1]['outcome'], $check['constraints'][1]['rule']['waste_type']['code']]);
    }

    public function test_closing_a_pickup_does_not_make_its_documents_complete(): void
    {
        $type = $this->type('FILS', 'textile_fibre');
        $lot = $this->lotData($type, '400');
        $pickup = $this->pickup([$lot['ulid']]);
        $this->api()->postJson("/api/v1/pickups/{$pickup}/submit")->assertOk()->assertJsonPath('data.compliance.state', 'not_due');
        $this->fresh($this->providerToken)->postJson("/api/v1/pickups/{$pickup}/confirm", ['confirmed_date' => now()->addDay()->toDateString()])->assertOk();
        $this->api()->postJson("/api/v1/pickups/{$pickup}/collect", ['lots' => [['lot' => $lot['ulid']]]])->assertOk()
            ->assertJsonPath('data.compliance.state', 'pending')->assertJsonPath('data.compliance.slips', 1)->assertJsonPath('data.compliance.pending_slips.0.waiting_for', 'producer');
        $this->fresh($this->providerToken)->postJson("/api/v1/pickups/{$pickup}/receive", ['lots' => [['lot_number' => $lot['lot_number'], 'received_weight_kg' => '400']]])->assertOk();
        $this->fresh($this->providerToken)->postJson("/api/v1/pickups/{$pickup}/treat", ['lots' => [['lot_number' => $lot['lot_number'], 'channel' => 'recycling']]])->assertOk();

        // Closed: the waste was received and treated. The slip is still unsigned, and the pickup says so.
        $this->api()->postJson("/api/v1/pickups/{$pickup}/complete")->assertOk()->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.compliance_state', 'pending')->assertJsonPath('data.compliance.slips_completed', 0);
        $listed = $this->api()->getJson('/api/v1/pickups?filter[documents_outstanding]=1')->assertOk()->json('data');
        $this->assertSame([[$pickup, 'completed', 'pending']], array_map(fn (array $row) => [$row['ulid'], $row['status'], $row['compliance_state']], $listed));
        $this->fresh($this->providerToken)->getJson("/api/v1/pickups/{$pickup}")->assertOk()->assertJsonPath('data.compliance.state', 'pending');

        // Only once every slip is signed by every party and archived are its documents complete.
        DB::table('hazardous_waste_manifests')->update(['status' => 'completed']);
        $this->api()->getJson("/api/v1/pickups/{$pickup}")->assertOk()->assertJsonPath('data.compliance.state', 'complete');
        $this->assertSame([], $this->api()->getJson('/api/v1/pickups?filter[documents_outstanding]=1')->assertOk()->json('data'));
    }

    public function test_capacity_emails_go_to_those_who_manage_the_stock_and_to_others_only_when_they_ask(): void
    {
        $operator = $this->makeMember($this->industrial, ['workshop_operator' => $this->site]);
        $operatorToken = $this->tokenFor($operator, $this->industrial);
        // Another company's manager of its own stock is nobody here.
        $elsewhere = $this->makeCompany();
        $stranger = $this->makeMember($elsewhere, ['environment_manager' => $this->makeSite($elsewhere, 'ALT')]);
        $type = $this->type('FILS', 'textile_fibre');
        $this->fresh($this->adminToken)->postJson('/api/v1/stock-thresholds', ['site' => $this->site->ulid, 'waste_type' => $type, 'max_quantity' => '100', 'unit' => 'kg'])->assertCreated();

        Notification::fake();
        $this->lot($type, '150');
        // Both see the alert in the application; the email goes to the one who manages the limits.
        Notification::assertSentTo($this->manager, BusinessEventNotification::class, fn (BusinessEventNotification $n, array $channels) => $n->type === 'stock.threshold_exceeded' && in_array('mail', $channels, true));
        Notification::assertSentTo($operator, BusinessEventNotification::class, fn (BusinessEventNotification $n, array $channels) => $n->type === 'stock.threshold_exceeded'
            && ! in_array('mail', $channels, true) && $channels !== []);
        Notification::assertNotSentTo($stranger, BusinessEventNotification::class);
        // Other events are not narrowed: a pickup request still reaches every recipient by email.
        $this->assertNull((new BusinessEventNotification('pickup.requested', [], null, $this->industrial->id))->mailByDefault);

        // The operator reads that it gets no email by default, and switches it on: from then on it does.
        $preference = collect($this->fresh($operatorToken)->getJson('/api/v1/me/notification-preferences')->assertOk()->json('data'))->firstWhere('notification_type', 'stock.threshold_exceeded');
        $this->assertSame([true, false, true, false], [$preference['database'], $preference['mail'], $preference['mail_available'], $preference['mail_by_default']]);
        $managerPreference = collect($this->api()->getJson('/api/v1/me/notification-preferences')->assertOk()->json('data'))->firstWhere('notification_type', 'stock.threshold_exceeded');
        $this->assertSame([true, true], [$managerPreference['mail'], $managerPreference['mail_by_default']]);
        $this->fresh($operatorToken)->putJson('/api/v1/me/notification-preferences', ['preferences' => [['notification_type' => 'stock.threshold_exceeded', 'channel' => 'mail', 'is_enabled' => true]]])->assertNoContent();
        $alert = new BusinessEventNotification('stock.threshold_exceeded', [], '/stock', $this->industrial->id, [$this->manager->id]);
        $this->assertContains('mail', $alert->via($operator));
        // A manager who switched it off gets none.
        $this->api()->putJson('/api/v1/me/notification-preferences', ['preferences' => [['notification_type' => 'stock.threshold_exceeded', 'channel' => 'mail', 'is_enabled' => false]]])->assertNoContent();
        $this->assertNotContains('mail', $alert->via($this->manager));
    }

    public function test_a_hazardous_waste_type_needs_its_code_and_a_legacy_one_holds_back_only_its_own_slip(): void
    {
        DB::table('regulatory_waste_codes')->insert(['code_system' => 'TN', 'code' => '04 02 16*', 'level' => 3, 'label' => '{"fr":"Résidus de teinture"}', 'is_hazardous' => true, 'is_selectable' => true]);
        // A new hazardous type comes with its code.
        $this->fresh($this->adminToken)->postJson('/api/v1/waste-types', ['code' => 'TEINT', 'name' => 'Teinture', 'waste_family' => 'chemical_sludge', 'is_hazardous' => true])
            ->assertStatus(422)->assertJsonValidationErrors(['regulatory_code']);

        // A legacy one has none: it is shown as such.
        $legacy = $this->tenant()->runAs($this->industrial, fn () => WasteType::query()->create(['code' => 'BOUES', 'name' => 'Boues', 'is_hazardous' => true,
            'waste_family_id' => DB::table('waste_families')->where('code', 'chemical_sludge')->value('id'), 'default_unit_id' => DB::table('units')->where('code', 'kg')->value('id'),
            'default_treatment_channel_id' => DB::table('treatment_channels')->where('code', 'recycling')->value('id'), 'activated_at' => now()])->ulid);
        $this->fresh($this->adminToken)->getJson("/api/v1/waste-types/{$legacy}")->assertOk()->assertJsonPath('data.regulatory_code_missing', true);
        $fibre = $this->type('FILS', 'textile_fibre');
        $partner = $this->fresh($this->adminToken)->postJson('/api/v1/external-partners', ['legal_name' => 'Eco Traitement', 'roles' => ['eliminator', 'transporter'],
            'accreditation_reference' => 'ANGED-77', 'covers_hazardous' => true])->assertCreated()->json('data.ulid');
        [$sludge, $yarn] = [$this->lotData($legacy, '300'), $this->lotData($fibre, '500')];

        // Its lots are not requested: the message names the type and the lots.
        $pickup = $this->api()->postJson('/api/v1/pickups', ['site' => $this->site->ulid, 'external_provider' => $partner, 'requested_date' => now()->addDay()->toDateString(),
            'lots' => [$sludge['ulid'], $yarn['ulid']]])->assertCreated()->json('data.ulid');
        $this->api()->postJson("/api/v1/pickups/{$pickup}/submit")->assertStatus(409)->assertJsonPath('code', 'REGULATORY_CODE_MISSING')
            ->assertJsonPath('context.waste_types.0.code', 'BOUES')->assertJsonPath('context.waste_types.0.lots', [$sludge['lot_number']]);

        // A pickup requested before the rule existed: it is loaded, the yarn gets its slip, the sludge waits for its code.
        DB::table('regulatory_waste_codes')->insert(['code_system' => 'TN', 'code' => 'TMP*', 'level' => 3, 'label' => '{}', 'is_hazardous' => true, 'is_selectable' => true]);
        $tmp = DB::table('regulatory_waste_codes')->where('code', 'TMP*')->value('id');
        DB::table('pickup_lots')->where('lot_number_snapshot', $sludge['lot_number'])->update(['regulatory_waste_code_id' => $tmp]);
        $this->api()->postJson("/api/v1/pickups/{$pickup}/submit")->assertOk();
        DB::table('pickup_lots')->where('lot_number_snapshot', $sludge['lot_number'])->update(['regulatory_waste_code_id' => null]);
        $this->api()->postJson("/api/v1/pickups/{$pickup}/confirm", ['confirmed_date' => now()->addDay()->toDateString()])->assertOk();
        $loaded = $this->api()->postJson("/api/v1/pickups/{$pickup}/collect", ['lots' => [['lot' => $sludge['ulid']], ['lot' => $yarn['ulid']]]])->assertOk()->json('data');
        $this->assertSame(['incomplete', 1, [$sludge['lot_number']], 'BOUES', []],
            [$loaded['compliance']['state'], $loaded['compliance']['slips'], $loaded['compliance']['missing_codes'][0]['lots'], $loaded['compliance']['missing_codes'][0]['code'], $loaded['compliance']['lots_without_slip']]);
        $first = DB::table('hazardous_waste_manifests')->first(['ulid', 'status', 'waste_type_id']);
        $this->assertSame((int) DB::table('waste_types')->where('code', 'FILS')->value('id'), (int) $first->waste_type_id);
        $this->api()->postJson("/api/v1/pickups/{$pickup}/manifests")->assertCreated()->assertJsonCount(1, 'data')->assertJsonPath('meta.lots_without_slip.0.lots', [$sludge['lot_number']]);

        // The code is completed on the type, once: its lots and the line no slip carries receive it; the slip is then issued.
        $this->fresh($this->adminToken)->putJson("/api/v1/waste-types/{$fibre}/regulatory-code", ['regulatory_code' => '04 02 16*'])->assertStatus(422);
        $this->fresh($this->adminToken)->putJson("/api/v1/waste-types/{$legacy}/regulatory-code", ['regulatory_code' => '04 02 16*'])->assertOk()
            ->assertJsonPath('data.regulatory_code', '04 02 16*')->assertJsonPath('data.regulatory_code_missing', false);
        $this->fresh($this->adminToken)->putJson("/api/v1/waste-types/{$legacy}/regulatory-code", ['regulatory_code' => 'TMP*'])->assertStatus(422);
        $this->assertSame(['04 02 16*', '04 02 16*'], [DB::table('regulatory_waste_codes')->where('id', DB::table('waste_lots')->where('ulid', $sludge['ulid'])->value('regulatory_waste_code_id'))->value('code'),
            DB::table('pickup_lots')->where('lot_number_snapshot', $sludge['lot_number'])->value('regulatory_code_snapshot')]);
        $this->assertTrue(DB::table('audit_logs')->where('event', 'regulatory_code_completed')->exists());
        $this->api()->postJson("/api/v1/pickups/{$pickup}/manifests")->assertCreated()->assertJsonCount(2, 'data')->assertJsonPath('meta.lots_without_slip', []);
        $this->api()->getJson("/api/v1/pickups/{$pickup}")->assertOk()->assertJsonPath('data.compliance.state', 'pending')->assertJsonPath('data.compliance.slips', 2);
        // The slip issued first is the same slip, untouched.
        $this->assertEquals($first, DB::table('hazardous_waste_manifests')->orderBy('id')->first(['ulid', 'status', 'waste_type_id']));
    }

    private function type(string $code, string $family, string $unit = 'kg', ?string $item = null): string
    {
        return $this->tenant()->runAs($this->industrial, fn () => WasteType::query()->create(['code' => $code, 'name' => $code,
            'waste_catalog_item_id' => $item === null ? null : DB::table('waste_catalog_items')->where('code', $item)->value('id'),
            'waste_family_id' => DB::table('waste_families')->where('code', $family)->value('id'), 'default_unit_id' => DB::table('units')->where('code', $unit)->value('id'),
            'default_treatment_channel_id' => DB::table('treatment_channels')->where('code', 'recycling')->value('id'), 'activated_at' => now()])->ulid);
    }

    /**
     * @param  list<array<string, mixed>>  $wastes
     */
    private function accepts(array $wastes): TestResponse
    {
        return $this->fresh($this->providerToken)->putJson('/api/v1/provider/profile', ['roles' => ['recycler', 'transporter'], 'accepted_wastes' => $wastes])->assertOk();
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function lotData(string $type, ?string $kg, array $extra = []): array
    {
        return $this->api()->postJson('/api/v1/lots', ['site' => $this->site->ulid, 'zone' => $this->zone->ulid, 'waste_type' => $type, 'net_weight_kg' => $kg] + $extra)->assertCreated()->json('data');
    }

    private function lot(string $type, ?string $kg): string
    {
        return $this->lotData($type, $kg)['ulid'];
    }

    /**
     * @param  list<string>  $lots
     * @return array<string, mixed>
     */
    private function check(array $lots): array
    {
        return $this->api()->postJson('/api/v1/provider-requirements/check', ['provider' => $this->provider->ulid, 'lots' => $lots])->assertOk()->json('data');
    }

    /**
     * @param  list<string>  $lots
     */
    private function pickup(array $lots): string
    {
        return $this->api()->postJson('/api/v1/pickups', ['site' => $this->site->ulid, 'provider' => $this->provider->ulid,
            'requested_date' => now()->addDay()->toDateString(), 'lots' => $lots])->assertCreated()->json('data.ulid');
    }

    private function api(): static
    {
        return $this->fresh($this->token);
    }

    private function fresh(string $token): static
    {
        $this->app->forgetScopedInstances();
        app('auth')->forgetGuards();

        return $this->withToken($token);
    }
}
