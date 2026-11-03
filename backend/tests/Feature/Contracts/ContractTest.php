<?php

namespace Tests\Feature\Contracts;

use App\Modules\Catalog\Models\WasteType;
use App\Modules\Contracts\Actions\ManageContracts;
use App\Modules\Identity\Models\User;
use App\Modules\Providers\Models\ProviderAccreditation;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\Sites\Models\Site;
use App\Modules\Sites\Models\Zone;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Waste\Actions\CreateWasteLot;
use App\Modules\Waste\DTOs\CreateWasteLotData;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Contracts with receivers and transporters: the terms, who reads them, and what they do to pickups — the contract a
 * pickup falls under, its prices at reception, its fees, and how many pickups it has had.
 */
class ContractTest extends TestCase
{
    use InteractsWithTenants;
    use RefreshDatabase;

    private Company $industrial;

    private Company $provider;

    private Site $site;

    private User $manager;

    private User $providerUser;

    private string $type;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([ReferenceDataSeeder::class, AuthorizationSeeder::class]);
        $this->industrial = $this->makeCompany();
        $this->provider = $this->makeCompany('provider');
        $this->site = $this->makeSite($this->industrial, 'SFX');
        $this->manager = $this->makeMember($this->industrial, ['client_admin' => null]);
        $this->providerUser = $this->makeMember($this->provider, ['provider_admin' => null]);
        $this->tenant()->runAs($this->provider, function () {
            ProviderProfile::query()->create(['is_collector' => true, 'is_sorter' => true, 'is_published' => true, 'published_at' => now()]);
            $file = DB::table('stored_files')->insertGetId(['ulid' => strtoupper((string) Str::ulid()), 'company_id' => $this->provider->id, 'disk' => 's3',
                'path' => 'tenants/p/agrement.pdf', 'original_name' => 'agrement.pdf', 'mime_type' => 'application/pdf', 'extension' => 'pdf',
                'size_bytes' => 1, 'checksum_sha256' => str_repeat('a', 64), 'purpose' => 'accreditation', 'created_at' => now(), 'updated_at' => now()]);
            $type = DB::table('accreditation_types')->insertGetId(['country_id' => DB::table('countries')->value('id'), 'code' => 'TN_COLLECT', 'name' => '{}']);
            ProviderAccreditation::query()->create(['accreditation_type_id' => $type, 'reference_number' => 'AG-1', 'valid_from' => now()->subYear(),
                'expires_on' => now()->addYear(), 'stored_file_id' => $file, 'review_status' => 'approved']);
        });
        $this->type = $this->tenant()->runAs($this->industrial, fn () => WasteType::query()->create(['code' => 'CHUTES', 'name' => 'Chutes',
            'waste_family_id' => DB::table('waste_families')->value('id'), 'default_unit_id' => DB::table('units')->where('code', 'kg')->value('id'), 'activated_at' => now()])->ulid);
    }

    public function test_a_contract_prices_its_pickups_at_reception_and_counts_them(): void
    {
        $contract = $this->asManager()->postJson('/api/v1/contracts', $this->terms())->assertCreated()
            ->assertJsonPath('data.status', 'draft')->assertJsonPath('data.state', 'draft')->assertJsonPath('data.prices.0.unit_price', '120.000')->json('data.ulid');
        // A draft applies to nothing; activated, the provider becomes a partner and is told.
        $this->asManager()->postJson("/api/v1/contracts/{$contract}/activate")->assertOk()->assertJsonPath('data.state', 'active');
        $this->assertTrue(DB::table('provider_partnerships')->where('provider_company_id', $this->provider->id)->where('status', 'active')->exists());

        $lot = $this->lot('500');
        $pickup = $this->asManager()->postJson('/api/v1/pickups', ['site' => $this->site->ulid, 'provider' => $this->provider->ulid,
            'requested_date' => now()->addDay()->toDateString(), 'lots' => [$lot]])->assertCreated()->assertJsonPath('data.contract.ulid', $contract)->json('data.ulid');
        $this->asManager()->postJson("/api/v1/pickups/{$pickup}/submit")->assertOk();
        $this->asProvider()->postJson("/api/v1/pickups/{$pickup}/confirm", ['confirmed_date' => now()->addDay()->toDateString()])->assertOk();
        $this->asManager()->postJson("/api/v1/pickups/{$pickup}/collect", ['lots' => [['lot' => $lot]]])->assertOk();
        $number = DB::table('pickup_lots')->value('lot_number_snapshot');
        $this->asProvider()->postJson("/api/v1/pickups/{$pickup}/receive", ['lots' => [['lot_number' => $number, 'received_weight_kg' => '480']]])->assertOk();

        // 120 per tonne, the provider pays: 0.48 t → 57.600; the transport fee of 30 is a cost.
        $this->assertSame('57.600', (string) DB::table('pickup_lots')->value('net_amount'));
        $this->asManager()->getJson("/api/v1/pickups/{$pickup}")->assertJsonPath('data.contract_fees_amount', '-30.000');
        $this->asManager()->getJson("/api/v1/contracts/{$contract}")->assertOk()->assertJsonPath('data.progress.pickups_done', 1)
            ->assertJsonPath('data.progress.remaining', 11)->assertJsonPath('data.progress.amounts.lots', '57.600')
            ->assertJsonPath('data.progress.amounts.pickup_fees', '-30.000')->assertJsonPath('data.pickups.0.ulid', $pickup);
    }

    public function test_the_pace_falls_on_the_chosen_days_and_who_requests_pickups_is_reminded(): void
    {
        // more pickups a week than days chosen is refused: one pickup a day at most
        $this->asManager()->postJson('/api/v1/contracts', $this->terms(['frequency' => 'weekly', 'pickups_per_period' => 4, 'weekdays' => ['mon', 'thu']]))
            ->assertStatus(409)->assertJsonPath('code', 'CONTRACT_PACE_DAYS');
        // once a week on Thursdays, from today: the next pickup falls on the first Thursday from today
        $contract = $this->asManager()->postJson('/api/v1/contracts', $this->terms(['starts_on' => now()->toDateString(), 'frequency' => 'weekly',
            'pickups_per_period' => 1, 'weekdays' => ['thu']]))->assertCreated()->json('data.ulid');
        $this->asManager()->postJson("/api/v1/contracts/{$contract}/activate")->assertOk();
        $due = $this->asManager()->getJson("/api/v1/contracts/{$contract}")->assertOk()->json('data.progress.next_due_on');
        $this->assertSame(4, Carbon::parse($due)->dayOfWeekIso);
        $this->assertTrue(Carbon::parse($due)->betweenIncluded(today(), today()->addDays(6)));

        // the day before and on the day, with nothing requested, whoever requests pickups is told once a day
        $this->travelTo(Carbon::parse($due)->subDay()->setTime(7, 0));
        $this->assertSame(1, ManageContracts::remindDue());
        $this->assertSame(0, ManageContracts::remindDue());
        $this->assertTrue(DB::table('notifications')->where('type', 'contract.pickup_due')->exists());
        $this->travelBack();
    }

    public function test_a_contract_carries_as_many_charges_as_it_has(): void
    {
        $contract = $this->asManager()->postJson('/api/v1/contracts', $this->terms(['starts_on' => now()->subMonths(2)->toDateString(), 'charges' => [
            ['label' => 'Location de la benne', 'amount' => '50', 'basis' => 'per_month', 'direction' => 'cost'],
            ['label' => 'Pesée', 'amount' => '10', 'basis' => 'per_pickup', 'direction' => 'cost'],
            ['label' => 'Frais de dossier', 'amount' => '30', 'basis' => 'once', 'direction' => 'cost'],
            ['label' => 'Prime de tri', 'amount' => '2', 'basis' => 'per_tonne', 'direction' => 'revenue'],
        ]]))->assertCreated()->assertJsonCount(4, 'data.charges')->assertJsonPath('data.charges.0.label', 'Location de la benne')->json('data.ulid');
        $this->asManager()->postJson("/api/v1/contracts/{$contract}/activate")->assertOk();
        // three months begun × 50, and the one-time charge once: −180 before any pickup
        $this->asManager()->getJson("/api/v1/contracts/{$contract}")->assertOk()->assertJsonPath('data.progress.amounts.monthly_fees', '-180.000');
        // a charge is refused without its name, or with an unknown basis
        $this->asManager()->putJson("/api/v1/contracts/{$contract}", ['charges' => [['label' => '', 'amount' => '1', 'basis' => 'per_pickup', 'direction' => 'cost']]])->assertUnprocessable();
        $this->asManager()->putJson("/api/v1/contracts/{$contract}", ['charges' => [['label' => 'X', 'amount' => '1', 'basis' => 'yearly', 'direction' => 'cost']]])->assertUnprocessable();
        // the whole list is replaced when sent
        $this->asManager()->putJson("/api/v1/contracts/{$contract}", ['charges' => []])->assertOk()->assertJsonCount(0, 'data.charges');
    }

    public function test_the_provider_reads_its_contracts_only_and_writes_none(): void
    {
        $contract = $this->asManager()->postJson('/api/v1/contracts', $this->terms())->json('data.ulid');
        $this->asManager()->postJson("/api/v1/contracts/{$contract}/activate")->assertOk();

        $this->asProvider()->getJson('/api/v1/contracts')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.company.legal_name', $this->industrial->legal_name);
        $this->asProvider()->getJson("/api/v1/contracts/{$contract}")->assertOk()->assertJsonPath('data.prices.0.direction', 'revenue');
        $this->asProvider()->postJson("/api/v1/contracts/{$contract}/terminate")->assertForbidden();
        $other = $this->makeCompany('provider');
        $this->withToken($this->tokenFor($this->makeMember($other, ['provider_admin' => null]), $other))->getJson("/api/v1/contracts/{$contract}")->assertNotFound();
        // An operator does not see the money of contracts.
        $operator = $this->makeMember($this->industrial, ['workshop_operator' => $this->site]);
        $this->withToken($this->tokenFor($operator, $this->industrial))->getJson('/api/v1/contracts')->assertForbidden();
    }

    public function test_contracts_do_not_overlap_and_the_party_does_what_the_contract_says(): void
    {
        $first = $this->asManager()->postJson('/api/v1/contracts', $this->terms())->json('data.ulid');
        $this->asManager()->postJson("/api/v1/contracts/{$first}/activate")->assertOk();
        $second = $this->asManager()->postJson('/api/v1/contracts', $this->terms(['starts_on' => now()->addMonth()->toDateString()]))->json('data.ulid');
        $this->asManager()->postJson("/api/v1/contracts/{$second}/activate")->assertStatus(409)->assertJsonPath('code', 'CONTRACT_OVERLAP');

        // This provider collects and sorts; it does not transport.
        $transport = $this->asManager()->postJson('/api/v1/contracts', $this->terms(['party_role' => 'transporter']))->json('data.ulid');
        $this->asManager()->postJson("/api/v1/contracts/{$transport}/activate")->assertStatus(409)->assertJsonPath('code', 'PARTNER_ROLE_MISMATCH');
    }

    public function test_a_terminated_contract_no_longer_applies(): void
    {
        $contract = $this->asManager()->postJson('/api/v1/contracts', $this->terms())->json('data.ulid');
        $this->asManager()->postJson("/api/v1/contracts/{$contract}/activate")->assertOk();
        $this->asManager()->postJson("/api/v1/contracts/{$contract}/terminate", ['reason' => 'Fin de collaboration', 'on' => now()->toDateString()])
            ->assertOk()->assertJsonPath('data.status', 'terminated')->assertJsonPath('data.ends_on', now()->toDateString());

        $this->asManager()->postJson('/api/v1/pickups', ['site' => $this->site->ulid, 'provider' => $this->provider->ulid,
            'requested_date' => now()->addDay()->toDateString(), 'lots' => [$this->lot('20')]])->assertCreated()->assertJsonPath('data.contract', null);
        $this->asManager()->putJson("/api/v1/contracts/{$contract}", ['title' => 'Autre'])->assertStatus(409)->assertJsonPath('code', 'CONTRACT_TERMINATED');
    }

    public function test_a_partner_without_an_account_that_sorts_receives_waste(): void
    {
        $partner = $this->asManager()->postJson('/api/v1/external-partners', ['legal_name' => 'Tri Textile', 'roles' => ['sorter']])->assertCreated()
            ->assertJsonPath('data.roles', ['sorter'])->json('data.ulid');
        $contract = $this->asManager()->postJson('/api/v1/contracts', ['external_partner' => $partner] + array_diff_key($this->terms(), ['provider' => 1]))->assertCreated()->json('data.ulid');
        $this->asManager()->postJson("/api/v1/contracts/{$contract}/activate")->assertOk()->assertJsonPath('data.party.external', true);
        $this->asManager()->postJson('/api/v1/external-partners', ['legal_name' => 'Brûleur', 'roles' => ['eliminator']])->assertUnprocessable();
    }

    /**
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function terms(array $override = []): array
    {
        return $override + [
            'provider' => $this->provider->ulid, 'party_role' => 'receiver', 'title' => 'Collecte des chutes 2026', 'reference' => 'C-2026-14',
            'starts_on' => now()->toDateString(), 'ends_on' => now()->addYear()->toDateString(), 'frequency' => 'monthly', 'pickups_per_period' => 1,
            'weekdays' => ['tue'], 'planned_pickups' => 12, 'pickup_fee' => '30', 'pickup_fee_direction' => 'cost', 'payment_terms_days' => 30,
            'prices' => [['waste_type' => $this->type, 'pricing_mode' => 'per_tonne', 'unit_price' => '120', 'direction' => 'revenue']],
        ];
    }

    private function lot(string $kg): string
    {
        return $this->tenant()->runAs($this->industrial, function () use ($kg) {
            $zone = Zone::query()->firstOrCreate(['code' => 'AIRE'], ['site_id' => $this->site->id, 'zone_type_id' => DB::table('zone_types')->value('id'), 'name' => 'Aire']);

            return app(CreateWasteLot::class)->handle(new CreateWasteLotData($this->site->ulid, $zone->ulid, $this->type, $kg), $this->manager)->ulid;
        });
    }

    private function asManager(): static
    {
        return $this->withToken($this->tokenFor($this->manager, $this->industrial));
    }

    private function asProvider(): static
    {
        return $this->withToken($this->tokenFor($this->providerUser, $this->provider));
    }
}
