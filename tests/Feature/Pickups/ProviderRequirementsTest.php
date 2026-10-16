<?php

namespace Tests\Feature\Pickups;

use App\Modules\Catalog\Models\WasteType;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\UserRoleAssignment;
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
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * What a provider asks of a collection — a minimum in a unit, a packaging — is judged on the whole pickup when it is
 * requested, each requirement on its own. It warns unless the provider made it blocking; what was decided stays on the
 * pickup whatever the provider changes afterwards.
 */
class ProviderRequirementsTest extends TestCase
{
    use InteractsWithTenants;
    use RefreshDatabase;

    private Company $industrial;

    private Company $provider;

    private Site $site;

    private Zone $zone;

    private User $manager;

    private string $providerToken;

    private string $token;

    private User $admin;

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
        $this->providerToken = $this->tokenFor($this->makeMember($this->provider, ['provider_admin' => null]), $this->provider);
        $this->zone = $this->tenant()->runAs($this->industrial, fn () => Zone::query()->create(['code' => 'AIRE', 'site_id' => $this->site->id,
            'zone_type_id' => DB::table('zone_types')->value('id'), 'name' => 'Aire']));

        $this->tenant()->runAs($this->provider, function () {
            ProviderProfile::query()->create(['is_collector' => true, 'is_recycler' => true, 'is_published' => true, 'published_at' => now()]);
            $file = DB::table('stored_files')->insertGetId(['ulid' => strtoupper((string) Str::ulid()), 'company_id' => $this->provider->id, 'disk' => 's3',
                'path' => 'tenants/p/agrement.pdf', 'original_name' => 'agrement.pdf', 'mime_type' => 'application/pdf', 'extension' => 'pdf',
                'size_bytes' => 1, 'checksum_sha256' => str_repeat('a', 64), 'purpose' => 'accreditation', 'created_at' => now(), 'updated_at' => now()]);
            $type = DB::table('accreditation_types')->insertGetId(['country_id' => DB::table('countries')->value('id'), 'code' => 'TN_COLLECT', 'name' => '{}']);
            ProviderAccreditation::query()->create(['accreditation_type_id' => $type, 'reference_number' => 'AG-1', 'valid_from' => now()->subYear(),
                'expires_on' => now()->addYear(), 'stored_file_id' => $file, 'review_status' => 'approved']);
        });
        $this->admin = $this->makeMember($this->industrial, ['client_admin' => null]);
        $this->adminToken = $this->tokenFor($this->admin, $this->industrial);
        // A role of the company's own: it requests pickups, and even holds the unrelated right to pass over a provider's
        // eligibility — but not the right to send a request against what the provider asks.
        $role = DB::table('roles')->insertGetId(['company_id' => $this->industrial->id, 'name' => 'requester', 'guard_name' => 'web', 'label' => '{"fr":"Demandeur"}',
            'audience' => 'industrial', 'is_system' => false, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('role_has_permissions')->insert(DB::table('permissions')->whereIn('name', ['app.web.access', 'lots.view', 'pickups.view', 'pickups.request', 'pickups.override_eligibility'])
            ->pluck('id')->map(fn ($id) => ['permission_id' => $id, 'role_id' => $role])->all());
        $requester = User::factory()->create();
        $this->tenant()->runAs($this->industrial, function () use ($requester, $role) {
            CompanyUser::query()->create(['user_id' => $requester->id, 'joined_at' => now()]);
            UserRoleAssignment::query()->create(['user_id' => $requester->id, 'role_id' => $role, 'site_id' => null]);
        });
        $this->requesterToken = $this->tokenFor($requester, $this->industrial);
        $this->fresh($this->adminToken)->postJson('/api/v1/provider-partnerships', ['provider' => $this->provider->ulid])->assertCreated();
    }

    public function test_a_minimum_weight_is_judged_on_the_whole_collection_and_only_warns_by_default(): void
    {
        $type = $this->type('CHUTES', 'textile_fibre');
        // Under its former name the minimum is in kilograms, and it warns: nothing that was informative starts refusing.
        $this->accepts([['channel' => 'recycling', 'family' => 'textile_fibre', 'min_quantity_kg' => '500']])
            ->assertJsonPath('data.accepted_wastes.0.min_quantity', '500.000')->assertJsonPath('data.accepted_wastes.0.min_unit', 'kg')
            ->assertJsonPath('data.accepted_wastes.0.is_blocking', false)->assertJsonPath('data.accepted_wastes.0.min_quantity_kg', '500.000');
        // The industrial reads the requirement in the directory, with its unit.
        $listed = collect($this->fresh($this->adminToken)->getJson('/api/v1/providers/directory')->assertOk()->json('data'))->firstWhere('ulid', $this->provider->ulid)['accepted_wastes'][0];
        $this->assertSame(['500.000', 'kg', false, null], [$listed['min_quantity'], $listed['min_unit'], $listed['is_blocking'], $listed['required_packaging_type']]);

        // Two lots of 200 and 120 kg: no lot reaches 500 kg, and that is not the question. Together they are 320 kg.
        [$a, $b] = [$this->lot($type, '200'), $this->lot($type, '120')];
        $check = $this->check([$a, $b])->json('data');
        $this->assertSame(['warning', false, []], [$check['outcome'], $check['blocked'], $check['failing']]);
        $this->assertSame(['minimum', 'kg', '320.000', '500.000', '180.000', 'warning', false, 2, 0], array_map(fn (string $key) => $check['constraints'][0][$key],
            ['kind', 'unit', 'used', 'min', 'shortfall', 'outcome', 'blocking', 'lots', 'missing_count']));

        // With a third lot the collection is 520 kg: the requirement is met.
        $c = $this->lot($type, '200');
        $this->assertSame(['respected', null], [$this->check([$a, $b, $c])->json('data.outcome'), $this->check([$a, $b, $c])->json('data.constraints.0.shortfall')]);

        // Below the minimum the request still goes out, and what was asked and answered stays on the pickup.
        $pickup = $this->pickup([$a, $b]);
        $this->api()->postJson("/api/v1/pickups/{$pickup}/submit")->assertOk()->assertJsonPath('data.status', 'requested')
            ->assertJsonPath('data.provider_requirements.outcome', 'warning')->assertJsonPath('data.provider_requirements.blocked', false)
            ->assertJsonPath('data.provider_requirements.override', null)->assertJsonPath('data.provider_requirements.constraints.0.shortfall', '180.000')
            ->assertJsonPath('data.provider_requirements.constraints.0.rule.family', 'textile_fibre');

        // The provider changes its rule: the pickup keeps the answer it was requested with, and the provider reads it too.
        $this->accepts([['channel' => 'recycling', 'family' => 'textile_fibre', 'min_quantity' => '100', 'min_unit' => 'kg', 'is_blocking' => true]]);
        $this->fresh($this->providerToken)->getJson("/api/v1/pickups/{$pickup}")->assertOk()
            ->assertJsonPath('data.provider_requirements.constraints.0.min', '500.000')->assertJsonPath('data.provider_requirements.constraints.0.blocking', false);
        $this->assertSame('respected', $this->check([$c])->json('data.outcome'));
    }

    public function test_a_blocking_minimum_refuses_the_request_unless_someone_entitled_sends_it_with_a_reason(): void
    {
        $type = $this->type('CHUTES', 'textile_fibre');
        $this->accepts([['channel' => 'recycling', 'family' => 'textile_fibre', 'min_quantity' => '0.5', 'min_unit' => 't', 'is_blocking' => true]])
            ->assertJsonPath('data.accepted_wastes.0.min_quantity_kg', '500.000');
        [$a, $b] = [$this->lot($type, '200'), $this->lot($type, '120')];

        $check = $this->check([$a, $b])->json('data');
        $this->assertSame(['exceeded', true, ['minimum:1'], true, 't', '0.320', '0.500', '0.180', 'provider'], [$check['outcome'], $check['blocked'], $check['failing'], $check['may_override'],
            $check['constraints'][0]['unit'], $check['constraints'][0]['used'], $check['constraints'][0]['min'], $check['constraints'][0]['shortfall'], $check['constraints'][0]['source']]);

        // Without a reason nobody sends it, whoever they are: the environment manager, the administrator.
        $pickup = $this->pickup([$a, $b]);
        foreach ([$this->token, $this->adminToken] as $token) {
            $this->fresh($token)->postJson("/api/v1/pickups/{$pickup}/submit")->assertStatus(409)->assertJsonPath('code', 'PROVIDER_REQUIREMENT_NOT_MET')
                ->assertJsonPath('context.constraints.0.shortfall', '0.180')->assertJsonPath('context.failing', ['minimum:1'])->assertJsonPath('context.may_override', true);
            $this->fresh($token)->postJson("/api/v1/pickups/{$pickup}/submit", ['requirement_override_reason' => '   '])->assertStatus(409)->assertJsonPath('code', 'PROVIDER_REQUIREMENT_NOT_MET');
        }
        // Nothing moved: the pickup is still a draft, the lots are still in stock, nothing was recorded as decided.
        $this->assertSame(['draft', null], [DB::table('pickups')->value('status'), DB::table('pickups')->value('provider_requirements')]);
        $this->assertSame(0, DB::table('waste_lots')->where('status', 'awaiting_pickup')->count());
        $this->assertSame(0, DB::table('audit_logs')->where('event', 'requirement_overridden')->count());

        // Someone who requests pickups but may not decide this cannot send it, reason or not — and passing over a
        // provider's eligibility, which this person may do, is not the same right.
        $this->fresh($this->requesterToken)->postJson('/api/v1/provider-requirements/check', ['provider' => $this->provider->ulid, 'lots' => [$a, $b]])->assertOk()->assertJsonPath('data.may_override', false);
        $this->fresh($this->requesterToken)->postJson("/api/v1/pickups/{$pickup}/submit")->assertStatus(409)->assertJsonPath('code', 'PROVIDER_REQUIREMENT_NOT_MET')->assertJsonPath('context.may_override', false);
        $this->fresh($this->requesterToken)->postJson("/api/v1/pickups/{$pickup}/submit", ['requirement_override_reason' => 'Accord du prestataire'])->assertStatus(409)
            ->assertJsonPath('code', 'PROVIDER_REQUIREMENT_NEEDS_AUTHORIZATION')->assertJsonPath('context.may_override', false);
        $this->assertSame('draft', DB::table('pickups')->value('status'));

        // The environment manager, with a reason: the request goes out, and who decided it and why is kept — on the
        // pickup, in the audit trail under its own event, and before the eyes of the provider, who still accepts or refuses.
        $this->api()->postJson("/api/v1/pickups/{$pickup}/submit", ['requirement_override_reason' => 'Accord téléphonique du prestataire, tournée déjà prévue'])->assertOk()
            ->assertJsonPath('data.status', 'requested')->assertJsonPath('data.provider_requirements.blocked', true)->assertJsonPath('data.provider_requirements.outcome', 'exceeded')
            ->assertJsonPath('data.provider_requirements.override.reason', 'Accord téléphonique du prestataire, tournée déjà prévue')
            ->assertJsonPath('data.provider_requirements.override.by', trim("{$this->manager->first_name} {$this->manager->last_name}"));
        $audit = DB::table('audit_logs')->where('event', 'requirement_overridden')->get();
        $this->assertCount(1, $audit);
        $this->assertSame(['pickups', DB::table('pickups')->value('id'), $this->manager->id, $this->industrial->id], [$audit[0]->auditable_type, (int) $audit[0]->auditable_id, (int) $audit[0]->actor_user_id, (int) $audit[0]->company_id]);
        $this->assertSame(['reason' => 'Accord téléphonique du prestataire, tournée déjà prévue', 'failing' => ['minimum:1'], 'outcome' => 'exceeded'], json_decode((string) $audit[0]->new_values, true));
        // The change of the pickup itself is in the trail too, with the requirement as it was judged.
        $this->assertTrue(DB::table('audit_logs')->where('auditable_type', 'pickups')->where('event', 'updated')->where('new_values', 'like', '%provider_requirements%')->exists());
        $this->fresh($this->providerToken)->getJson("/api/v1/pickups/{$pickup}")->assertOk()->assertJsonPath('data.provider_requirements.override.reason', 'Accord téléphonique du prestataire, tournée déjà prévue')
            ->assertJsonPath('data.provider_requirements.constraints.0.source', 'provider');
        $this->fresh($this->providerToken)->postJson("/api/v1/pickups/{$pickup}/refuse", ['reason' => 'Minimum non atteint'])->assertOk()->assertJsonPath('data.status', 'refused');

        // The administrator may do the same, and is named for it.
        $again = $this->pickup([$a, $b]);
        $this->fresh($this->adminToken)->postJson("/api/v1/pickups/{$again}/submit", ['requirement_override_reason' => 'Complément de tournée'])->assertOk()
            ->assertJsonPath('data.provider_requirements.override.by', trim("{$this->admin->first_name} {$this->admin->last_name}"));
        $this->assertSame([$this->manager->id, $this->admin->id], DB::table('audit_logs')->where('event', 'requirement_overridden')->orderBy('id')->pluck('actor_user_id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_a_weight_nobody_measured_is_not_a_minimum_reached(): void
    {
        $type = $this->type('CHUTES', 'textile_fibre');
        $this->accepts([['channel' => 'recycling', 'family' => 'textile_fibre', 'min_quantity' => '500', 'min_unit' => 'kg', 'is_blocking' => true]]);
        $weighed = $this->lot($type, '320');
        $unknown = $this->lotData($type, null);
        $estimated = $this->lotData($type, '400', ['weight_estimated' => true]);

        // Blocking, 320 kg known and a lot nobody weighed: not "met", not "not met" — it cannot be verified, and it stops.
        $check = $this->check([$weighed, $unknown['ulid']])->json('data');
        $this->assertSame(['unverifiable', true, '320.000', '180.000', [$unknown['lot_number']]], [$check['outcome'], $check['blocked'], $check['constraints'][0]['used'],
            $check['constraints'][0]['shortfall'], $check['constraints'][0]['missing_lots']]);
        $pickup = $this->pickup([$weighed, $unknown['ulid']]);
        $this->api()->postJson("/api/v1/pickups/{$pickup}/submit")->assertStatus(409)->assertJsonPath('code', 'PROVIDER_REQUIREMENT_UNVERIFIABLE');
        // An estimate is not a measure either: 320 kg measured and 400 kg estimated are not 720 kg.
        $this->assertSame(['unverifiable', [$estimated['lot_number']]], [$this->check([$weighed, $estimated['ulid']])->json('data.outcome'), $this->check([$weighed, $estimated['ulid']])->json('data.constraints.0.missing_lots')]);

        // Reached on what is measured: an unweighed lot can only add to it. Met, and the lot is still named.
        $more = $this->lot($type, '200');
        $check = $this->check([$weighed, $more, $unknown['ulid']])->json('data');
        $this->assertSame(['respected', false, '520.000', 1], [$check['outcome'], $check['blocked'], $check['constraints'][0]['used'], $check['constraints'][0]['missing_count']]);

        // The same requirement as a warning: the missing weight is explained and the request goes out.
        $this->accepts([['channel' => 'recycling', 'family' => 'textile_fibre', 'min_quantity' => '500', 'min_unit' => 'kg']]);
        $this->api()->postJson("/api/v1/pickups/{$pickup}/submit")->assertOk()->assertJsonPath('data.provider_requirements.outcome', 'unverifiable')
            ->assertJsonPath('data.provider_requirements.blocked', false)->assertJsonPath('data.provider_requirements.constraints.0.missing_lots', [$unknown['lot_number']]);
    }

    public function test_minimums_in_packages_pieces_and_volume_and_a_packaging_asked_for_are_separate_answers(): void
    {
        $scraps = $this->type('CHUTES', 'textile_fibre');
        $cones = $this->type('CONES', 'packaging', 'piece');
        $baths = $this->type('BAINS', 'chemical_sludge', 'l');
        $this->accepts([
            // At least 20 bales, in bales.
            ['channel' => 'recycling', 'family' => 'textile_fibre', 'min_quantity' => '20', 'min_unit' => 'package', 'min_packaging_type' => 'bale', 'required_packaging_type' => 'bale'],
            ['channel' => 'recycling', 'family' => 'textile_fibre', 'min_quantity' => '300', 'min_unit' => 'kg'],
            ['channel' => 'recycling', 'family' => 'packaging', 'min_quantity' => '500', 'min_unit' => 'piece'],
            ['channel' => 'recycling', 'family' => 'chemical_sludge', 'min_quantity' => '1', 'min_unit' => 'm3'],
        ])->assertJsonPath('data.accepted_wastes.0.min_packaging_type', 'bale')->assertJsonPath('data.accepted_wastes.0.required_packaging_type', 'bale')
            ->assertJsonPath('data.accepted_wastes.0.min_quantity_kg', null);
        $this->fresh($this->providerToken)->putJson('/api/v1/provider/profile', ['roles' => ['collector', 'recycler'], 'accepted_wastes' => [['channel' => 'recycling', 'family' => 'packaging', 'min_quantity' => '20', 'min_unit' => 'package']]])
            ->assertStatus(422)->assertJsonValidationErrors('accepted_wastes.0.min_packaging_type');

        // One lot of 12 bales is 12 bales; a lot of 5 bags is not bales, and it is not in the packaging asked for.
        $twelve = $this->lot($scraps, '240', ['packaging_type' => 'bale', 'package_count' => 12]);
        $bags = $this->lotData($scraps, '100', ['packaging_type' => 'bag', 'package_count' => 5]);
        $check = collect($this->check([$twelve, $bags['ulid']])->json('data.constraints'))->keyBy('key');
        $this->assertSame(['minimum:1', 'packaging:1', 'minimum:2'], $check->keys()->all());
        $this->assertSame(['package', 'bale', 12, 20, 8, 'warning', 1, [$bags['lot_number']]], array_map(fn (string $key) => $check['minimum:1'][$key],
            ['unit', 'packaging_type', 'used', 'min', 'shortfall', 'outcome', 'outside_count', 'outside_lots']));
        $this->assertSame(['packaging', 'bale', 'warning', 2, 1, [$bags['lot_number']]], array_map(fn (string $key) => $check['packaging:1'][$key],
            ['kind', 'packaging_type', 'outcome', 'lots', 'outside_count', 'outside_lots']));
        // The weight of the same collection is another answer: 340 kg for 300 asked.
        $this->assertSame(['340.000', 'respected'], [$check['minimum:2']['used'], $check['minimum:2']['outcome']]);

        // Ten more bales: 22 for 20 asked, and every lot in bales.
        $ten = $this->lot($scraps, '200', ['packaging_type' => 'bale', 'package_count' => 10]);
        $this->assertSame(['respected', 'respected', 'respected'], array_column($this->check([$twelve, $ten])->json('data.constraints'), 'outcome'));

        // Pieces and litres are counted in what the lots declare; a waste of another family is not concerned by them.
        $pieces = $this->lot($cones, null, ['quantity' => '300', 'unit' => 'piece']);
        $litres = $this->lot($baths, null, ['quantity' => '600', 'unit' => 'l']);
        $check = collect($this->check([$pieces, $litres])->json('data.constraints'));
        $this->assertSame([['piece', '300.000', '500.000', '200.000', 1], ['m3', '0.600', '1.000', '0.400', 1]],
            $check->map(fn (array $c) => [$c['unit'], $c['used'], $c['min'], $c['shortfall'], $c['lots']])->all());

        // Asked for as a refusal, the packaging stops the request and names the lots.
        $this->accepts([['channel' => 'recycling', 'family' => 'textile_fibre', 'required_packaging_type' => 'bale', 'is_blocking' => true]]);
        $pickup = $this->pickup([$twelve, $bags['ulid']]);
        $this->api()->postJson("/api/v1/pickups/{$pickup}/submit")->assertStatus(409)->assertJsonPath('code', 'PROVIDER_REQUIREMENT_NOT_MET')
            ->assertJsonPath('context.constraints.0.kind', 'packaging')->assertJsonPath('context.constraints.0.outside_lots', [$bags['lot_number']]);
    }

    public function test_a_requirement_concerns_only_the_waste_and_the_treatment_it_names_and_nothing_else_about_the_provider_changes(): void
    {
        $scraps = $this->type('CHUTES', 'textile_fibre');
        $boxes = $this->type('CARTON', 'packaging');
        $this->accepts([['channel' => 'recycling', 'family' => 'textile_fibre', 'min_quantity' => '500', 'min_unit' => 'kg', 'is_blocking' => true]]);

        // Another family, or another treatment than the one the rule names: the provider asked nothing of it. It is
        // still a provider that may be chosen — accepted waste never decided that, and still does not.
        $carton = $this->lot($boxes, '10');
        $this->assertSame([[], null, false], [$this->check([$carton])->json('data.constraints'), $this->check([$carton])->json('data.outcome'), $this->check([$carton])->json('data.blocked')]);
        $small = $this->lot($scraps, '10');
        $this->assertSame([], $this->check([$small], 'energy_recovery')->json('data.constraints'));
        $pickup = $this->pickup([$carton]);
        $this->api()->postJson("/api/v1/pickups/{$pickup}/submit")->assertOk()->assertJsonPath('data.provider_requirements', null);
        $other = $this->api()->postJson('/api/v1/pickups', ['site' => $this->site->ulid, 'provider' => $this->provider->ulid, 'requested_date' => now()->addDay()->toDateString(),
            'lots' => [$small], 'treatment_channel' => 'energy_recovery'])->assertCreated()->json('data.ulid');
        $this->api()->postJson("/api/v1/pickups/{$other}/submit")->assertOk()->assertJsonPath('data.provider_requirements', null);

        // Only the provider sets what it asks; only people who request pickups ask whether it is met.
        $this->api()->putJson('/api/v1/provider/profile', ['roles' => ['collector', 'recycler'], 'accepted_wastes' => []])->assertForbidden();
        $this->fresh($this->providerToken)->postJson('/api/v1/provider-requirements/check', ['provider' => $this->provider->ulid, 'lots' => [$small]])->assertStatus(403);
        $stranger = $this->makeCompany();
        $this->makeSite($stranger, 'TUN');
        $this->fresh($this->tokenFor($this->makeMember($stranger, ['client_admin' => null]), $stranger))
            ->postJson('/api/v1/provider-requirements/check', ['provider' => $this->provider->ulid, 'lots' => [$small]])->assertStatus(422);
    }

    public function test_a_company_records_what_a_partner_without_an_account_asks_and_it_is_judged_as_its_own_unconfirmed_note(): void
    {
        $type = $this->type('CHUTES', 'textile_fibre');
        $partner = $this->fresh($this->adminToken)->postJson('/api/v1/external-partners', ['legal_name' => 'Fibres du Cap', 'roles' => ['recycler', 'transporter']])->assertCreated()
            ->assertJsonPath('data.requirements', [])->assertJsonPath('data.requirements_to_review', 0)->json('data.ulid');
        $put = fn (array $lines, ?string $token = null) => $this->fresh($token ?? $this->adminToken)->putJson("/api/v1/external-partners/{$partner}/requirements", ['requirements' => $lines]);

        // A line asks something, in a unit, and a minimum in packages says which packaging.
        $put([['family' => 'textile_fibre', 'channel' => 'recycling']])->assertStatus(422)->assertJsonValidationErrors('requirements.0.min_quantity');
        $put([['family' => 'textile_fibre', 'channel' => 'recycling', 'min_quantity' => '500']])->assertStatus(422)->assertJsonValidationErrors('requirements.0.min_unit');
        $put([['family' => 'textile_fibre', 'channel' => 'recycling', 'min_quantity' => '5', 'min_unit' => 'package']])->assertStatus(422)->assertJsonValidationErrors('requirements.0.min_packaging_type');
        // Only who manages the partners of the company writes them down; another company does not reach them.
        $put([['family' => 'textile_fibre', 'channel' => 'recycling', 'min_quantity' => '500', 'min_unit' => 'kg']], $this->requesterToken)->assertForbidden();
        $stranger = $this->makeCompany();
        $put([], $this->tokenFor($this->makeMember($stranger, ['client_admin' => null]), $stranger))->assertNotFound();

        // Written down from the contract: a warning unless the company says it must refuse.
        $line = $put([['family' => 'textile_fibre', 'channel' => 'recycling', 'min_quantity' => '500', 'min_unit' => 'kg', 'contract_reference' => 'Contrat FC-2026-014, art. 3', 'notes' => 'Tournée mensuelle']])
            ->assertOk()->assertJsonPath('data.requirements.0.min_quantity', '500.000')->assertJsonPath('data.requirements.0.is_blocking', false)
            ->assertJsonPath('data.requirements.0.contract_reference', 'Contrat FC-2026-014, art. 3')->assertJsonPath('data.requirements.0.needs_review', false)
            ->assertJsonPath('data.requirements.0.updated_by', trim("{$this->admin->first_name} {$this->admin->last_name}"))->json('data.requirements.0.ulid');
        $created = DB::table('audit_logs')->where('auditable_type', 'partner_requirements')->where('event', 'created')->first();
        $this->assertSame([$this->admin->id, $this->industrial->id, $line], [(int) $created->actor_user_id, (int) $created->company_id, $created->auditable_ulid]);
        $this->assertStringContainsString('FC-2026-014', (string) $created->new_values);

        // Judged like any requirement — on the whole collection, in its unit — and every answer says where it comes from.
        [$a, $b] = [$this->lot($type, '200'), $this->lot($type, '120')];
        $ask = fn (array $lots) => $this->api()->postJson('/api/v1/provider-requirements/check', ['external_provider' => $partner, 'lots' => $lots])->assertOk()->json('data');
        $check = $ask([$a, $b]);
        $this->assertSame(['warning', false, 'internal', '320.000', '180.000', 'internal', 'Contrat FC-2026-014, art. 3', false], [$check['outcome'], $check['blocked'], $check['constraints'][0]['source'],
            $check['constraints'][0]['used'], $check['constraints'][0]['shortfall'], $check['constraints'][0]['rule']['source'], $check['constraints'][0]['rule']['contract_reference'], $check['constraints'][0]['rule']['needs_review']]);
        $this->api()->postJson('/api/v1/provider-requirements/check', ['provider' => $this->provider->ulid, 'external_provider' => $partner, 'lots' => [$a]])->assertStatus(422);
        $external = fn (array $lots) => $this->api()->postJson('/api/v1/pickups', ['site' => $this->site->ulid, 'external_provider' => $partner, 'requested_date' => now()->addDay()->toDateString(), 'lots' => $lots])->assertCreated()->json('data.ulid');
        $pickup = $external([$a]);
        $this->api()->postJson("/api/v1/pickups/{$pickup}/submit")->assertOk()->assertJsonPath('data.provider_requirements.outcome', 'warning')
            ->assertJsonPath('data.provider_requirements.constraints.0.source', 'internal')->assertJsonPath('data.provider_requirements.constraints.0.rule.contract_reference', 'Contrat FC-2026-014, art. 3');

        // Made blocking, explicitly: the change is in the trail with what it was and who made it, and it now refuses.
        $put([['ulid' => $line, 'family' => 'textile_fibre', 'channel' => 'recycling', 'min_quantity' => '500', 'min_unit' => 'kg', 'is_blocking' => true, 'contract_reference' => 'Contrat FC-2026-014, art. 3', 'notes' => 'Tournée mensuelle']])
            ->assertOk()->assertJsonPath('data.requirements.0.ulid', $line)->assertJsonPath('data.requirements.0.is_blocking', true);
        $updated = DB::table('audit_logs')->where('auditable_type', 'partner_requirements')->where('event', 'updated')->first();
        $this->assertSame([false, true, $this->admin->id], [(bool) json_decode((string) $updated->old_values, true)['is_blocking'], (bool) json_decode((string) $updated->new_values, true)['is_blocking'], (int) $updated->actor_user_id]);
        $this->assertSame([true, true], [$ask([$b])['blocked'], $ask([$b])['may_override']]);
        $second = $external([$b]);
        $this->api()->postJson("/api/v1/pickups/{$second}/submit")->assertStatus(409)->assertJsonPath('code', 'PROVIDER_REQUIREMENT_NOT_MET')->assertJsonPath('context.provider', 'Fibres du Cap');
        $this->api()->postJson("/api/v1/pickups/{$second}/submit", ['requirement_override_reason' => 'Enlèvement exceptionnel convenu par email du 12/10'])->assertOk()
            ->assertJsonPath('data.provider_requirements.override.reason', 'Enlèvement exceptionnel convenu par email du 12/10');
        // The pickup already requested keeps the warning it was judged with.
        $this->api()->getJson("/api/v1/pickups/{$pickup}")->assertOk()->assertJsonPath('data.provider_requirements.constraints.0.blocking', false);

        // A line left out of the list is removed, and that too is in the trail.
        $put([])->assertOk()->assertJsonPath('data.requirements', []);
        $this->assertSame(1, DB::table('audit_logs')->where('auditable_type', 'partner_requirements')->where('event', 'deleted')->where('auditable_ulid', $line)->count());
        $this->assertSame([], $ask([$b])['constraints']);
    }

    public function test_when_the_partner_opens_an_account_what_the_company_wrote_down_waits_for_a_review_and_is_never_dropped_silently(): void
    {
        $type = $this->type('CHUTES', 'textile_fibre');
        $partner = $this->fresh($this->adminToken)->postJson('/api/v1/external-partners', ['legal_name' => 'Ancien partenaire', 'roles' => ['recycler', 'transporter']])->assertCreated()->json('data.ulid');
        $lines = $this->fresh($this->adminToken)->putJson("/api/v1/external-partners/{$partner}/requirements", ['requirements' => [
            ['family' => 'textile_fibre', 'channel' => 'recycling', 'min_quantity' => '500', 'min_unit' => 'kg', 'contract_reference' => 'Contrat 2025'],
            ['family' => 'textile_fibre', 'channel' => 'recycling', 'required_packaging_type' => 'bale'],
        ]])->assertOk()->json('data.requirements');
        $review = fn (string $line, string $decision) => $this->fresh($this->adminToken)->postJson("/api/v1/external-partners/{$partner}/requirements/{$line}/review", ['decision' => $decision]);
        // Without an account there is nothing to reconcile: the lines are simply edited.
        $review($lines[0]['ulid'], 'keep')->assertStatus(409)->assertJsonPath('code', 'PARTNER_NOT_LINKED');

        // The partner opens an account and publishes its own minimum; the company ties the two.
        $this->accepts([['channel' => 'recycling', 'family' => 'textile_fibre', 'min_quantity' => '300', 'min_unit' => 'kg']]);
        $this->fresh($this->adminToken)->postJson("/api/v1/external-partners/{$partner}/link", ['provider' => $this->provider->ulid])->assertOk()
            ->assertJsonPath('data.requirements_to_review', 2)->assertJsonPath('data.requirements.0.needs_review', true)->assertJsonPath('data.requirements.0.min_quantity', '500.000')
            // Shown side by side: what the provider says itself, and what the company had noted.
            ->assertJsonPath('data.provider_requirements.0.min_quantity', '300.000')->assertJsonCount(1, 'data.provider_requirements');
        $this->assertSame(2, DB::table('partner_requirements')->count());

        // Until someone looks at them, the notes still apply to the pickups sent to that provider — marked as the
        // company's own and to be reviewed — beside what the provider publishes. Nothing was replaced.
        $lot = $this->lotData($type, '400', ['packaging_type' => 'bag', 'package_count' => 4]);
        $check = collect($this->check([$lot['ulid']])->json('data.constraints'));
        $this->assertSame([['provider', 'minimum', '300.000', 'respected', false], ['internal', 'minimum', '500.000', 'warning', true], ['internal', 'packaging', null, 'warning', true]],
            $check->map(fn (array $c) => [$c['source'], $c['kind'], $c['min'] ?? null, $c['outcome'], $c['rule']['needs_review']])->all());

        // Kept: it stays the company's own requirement and stops asking for a review. Removed: the provider's word stands alone.
        $review($lines[0]['ulid'], 'keep')->assertOk()->assertJsonPath('data.requirements_to_review', 1)->assertJsonPath('data.requirements.0.needs_review', false);
        $review($lines[1]['ulid'], 'remove')->assertOk()->assertJsonPath('data.requirements_to_review', 0)->assertJsonCount(1, 'data.requirements');
        $this->assertSame(1, DB::table('audit_logs')->where('auditable_type', 'partner_requirements')->where('event', 'deleted')->where('auditable_ulid', $lines[1]['ulid'])->count());
        $this->assertTrue(DB::table('audit_logs')->where('auditable_type', 'partner_requirements')->where('event', 'updated')->where('auditable_ulid', $lines[0]['ulid'])->where('new_values', 'like', '%reviewed_at%')->exists());
        $check = collect($this->check([$lot['ulid']])->json('data.constraints'));
        $this->assertSame([['provider', '300.000', false], ['internal', '500.000', false]], $check->map(fn (array $c) => [$c['source'], $c['min'], $c['rule']['needs_review']])->all());
        // A line of another partner is not reviewed through this one.
        $other = $this->fresh($this->adminToken)->postJson('/api/v1/external-partners', ['legal_name' => 'Autre', 'roles' => ['recycler']])->assertCreated()->json('data.ulid');
        $this->fresh($this->adminToken)->postJson("/api/v1/external-partners/{$other}/requirements/{$lines[0]['ulid']}/review", ['decision' => 'remove'])->assertNotFound();
    }

    private function type(string $code, string $family, string $unit = 'kg'): string
    {
        return $this->tenant()->runAs($this->industrial, fn () => WasteType::query()->create(['code' => $code, 'name' => $code,
            'waste_family_id' => DB::table('waste_families')->where('code', $family)->value('id'), 'default_unit_id' => DB::table('units')->where('code', $unit)->value('id'),
            'default_treatment_channel_id' => DB::table('treatment_channels')->where('code', 'recycling')->value('id'), 'activated_at' => now()])->ulid);
    }

    /**
     * @param  list<array<string, mixed>>  $wastes
     */
    private function accepts(array $wastes): TestResponse
    {
        return $this->fresh($this->providerToken)->putJson('/api/v1/provider/profile', ['roles' => ['collector', 'recycler'], 'accepted_wastes' => $wastes])->assertOk();
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function lotData(string $type, ?string $kg, array $extra = []): array
    {
        return $this->api()->postJson('/api/v1/lots', ['site' => $this->site->ulid, 'zone' => $this->zone->ulid, 'waste_type' => $type, 'net_weight_kg' => $kg] + $extra)->assertCreated()->json('data');
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function lot(string $type, ?string $kg, array $extra = []): string
    {
        return $this->lotData($type, $kg, $extra)['ulid'];
    }

    /**
     * @param  list<string>  $lots
     */
    private function check(array $lots, ?string $channel = null): TestResponse
    {
        return $this->api()->postJson('/api/v1/provider-requirements/check', ['provider' => $this->provider->ulid, 'lots' => $lots, 'treatment_channel' => $channel])->assertOk();
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
        // The auth guard caches the user between requests of one test: switching tokens must reset it.
        app('auth')->forgetGuards();

        return $this->withToken($token);
    }
}
