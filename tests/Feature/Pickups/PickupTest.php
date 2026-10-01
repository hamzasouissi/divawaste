<?php

namespace Tests\Feature\Pickups;

use App\Modules\Catalog\Models\WasteType;
use App\Modules\Identity\Models\User;
use App\Modules\Pickups\Actions\PickupWorkflow;
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
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class PickupTest extends TestCase
{
    use InteractsWithTenants;
    use RefreshDatabase;

    private Company $industrial;

    private Company $provider;

    private Site $site;

    private User $manager;

    private User $providerUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([ReferenceDataSeeder::class, AuthorizationSeeder::class]);
        $this->industrial = $this->makeCompany();
        $this->provider = $this->makeCompany('provider');
        $this->site = $this->makeSite($this->industrial, 'SFX');
        $this->manager = $this->makeMember($this->industrial, ['environment_manager' => $this->site]);
        $this->providerUser = $this->makeMember($this->provider, ['provider_admin' => null]);

        $this->tenant()->runAs($this->provider, function () {
            ProviderProfile::query()->create(['is_recycler' => true, 'is_published' => true, 'published_at' => now()]);
            $file = DB::table('stored_files')->insertGetId(['ulid' => '01JC2Z8M4W3V9R6T1QXK5B7N2D', 'company_id' => $this->provider->id, 'disk' => 's3',
                'path' => 'tenants/p/agrement.pdf', 'original_name' => 'agrement.pdf', 'mime_type' => 'application/pdf', 'extension' => 'pdf',
                'size_bytes' => 1, 'checksum_sha256' => str_repeat('a', 64), 'purpose' => 'accreditation', 'created_at' => now(), 'updated_at' => now()]);
            $type = DB::table('accreditation_types')->insertGetId(['country_id' => DB::table('countries')->value('id'), 'code' => 'TN_COLLECT', 'name' => '{}']);
            ProviderAccreditation::query()->create(['accreditation_type_id' => $type, 'reference_number' => 'AG-1', 'valid_from' => now()->subYear(),
                'expires_on' => now()->addYear(), 'stored_file_id' => $file, 'review_status' => 'approved']);
        });

        $this->withToken($this->tokenFor($this->makeMember($this->industrial, ['client_admin' => null]), $this->industrial))
            ->postJson('/api/v1/provider-partnerships', ['provider' => $this->provider->ulid])->assertCreated();
    }

    public function test_full_lifecycle_with_snapshots_and_weight_variance(): void
    {
        [$a, $b] = [$this->lot('100'), $this->lot('50')];
        $pickup = $this->asManager()->postJson('/api/v1/pickups', $this->payload([$a, $b]))
            ->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.lots.0.waste_type_label_snapshot', 'Chutes')->json('data.ulid');

        $this->asManager()->postJson("/api/v1/pickups/{$pickup}/submit")->assertOk()->assertJsonPath('data.status', 'requested');
        $this->assertSame(2, DB::table('waste_lots')->where('status', 'awaiting_pickup')->count());

        $this->asProvider()->postJson("/api/v1/pickups/{$pickup}/confirm", ['confirmed_date' => now()->addDay()->toDateString()])->assertOk();
        $this->asManager()->postJson("/api/v1/pickups/{$pickup}/collect", ['lots' => [['lot' => $a], ['lot' => $b, 'departure_weight_kg' => '48']]])
            ->assertOk()->assertJsonPath('data.departure_weight_kg', '148.000');

        $numbers = DB::table('waste_lots')->orderBy('id')->pluck('lot_number')->all();
        $this->asProvider()->postJson("/api/v1/pickups/{$pickup}/receive", ['lots' => [
            ['lot_number' => $numbers[0], 'received_weight_kg' => '100'], ['lot_number' => $numbers[1], 'received_weight_kg' => '40'],
        ]])->assertOk()->assertJsonPath('data.status', 'received')->assertJsonPath('data.weight_variance_pct', '-5.41')->assertJsonPath('data.variance_flagged', true);

        $this->assertSame([false, true], DB::table('pickup_lots')->orderBy('id')->pluck('variance_flagged')->map(fn ($v) => (bool) $v)->all());
        $this->assertSame(2, DB::table('waste_lots')->where('status', 'collected')->count());
    }

    public function test_variance_threshold_boundaries(): void
    {
        $this->assertFalse(PickupWorkflow::variance('100', '95', '5.00')[2]);
        $this->assertTrue(PickupWorkflow::variance('100', '94.99', '5.00')[2]);
        $this->assertTrue(PickupWorkflow::variance('100', '105.01', '5.00')[2]);
        $this->assertSame(['0.000', null, false], PickupWorkflow::variance('0', '0', '5.00'));
    }

    public function test_expired_accreditation_blocks_submission(): void
    {
        DB::table('provider_accreditations')->update(['expires_on' => now()->subDay()]);
        $pickup = $this->asManager()->postJson('/api/v1/pickups', $this->payload([$this->lot('10')]))->json('data.ulid');

        $this->asManager()->postJson("/api/v1/pickups/{$pickup}/submit")->assertStatus(409)->assertJsonPath('code', 'PROVIDER_NOT_ELIGIBLE');
    }

    public function test_refusal_releases_lots_and_a_lot_is_in_one_active_pickup(): void
    {
        $lot = $this->lot('10');
        $pickup = $this->asManager()->postJson('/api/v1/pickups', $this->payload([$lot]))->json('data.ulid');
        $this->asManager()->postJson('/api/v1/pickups', $this->payload([$lot]))->assertStatus(409);
        $this->asManager()->postJson("/api/v1/pickups/{$pickup}/cancel", ['reason' => 'x'])->assertOk();
        $this->asManager()->postJson('/api/v1/pickups', $this->payload([$lot]))->assertCreated();
    }

    public function test_provider_sees_only_its_pickups_and_never_lots(): void
    {
        $lot = $this->lot('10');
        $pickup = $this->asManager()->postJson('/api/v1/pickups', $this->payload([$lot]))->json('data.ulid');
        $this->asManager()->postJson("/api/v1/pickups/{$pickup}/submit")->assertOk();

        $this->asProvider()->getJson("/api/v1/pickups/{$pickup}")->assertOk();
        $this->asProvider()->getJson("/api/v1/lots/{$lot}")->assertNotFound();
        $this->asProvider()->postJson("/api/v1/pickups/{$pickup}/refuse", ['reason' => 'Full'])->assertOk()->assertJsonPath('data.status', 'refused');
        $this->assertSame('stored', DB::table('waste_lots')->value('status'));

        $other = $this->makeCompany('provider');
        $this->withToken($this->tokenFor($this->makeMember($other, ['provider_admin' => null]), $other))
            ->getJson("/api/v1/pickups/{$pickup}")->assertNotFound();
    }

    public function test_operator_cannot_request_a_pickup(): void
    {
        $operator = $this->makeMember($this->industrial, ['workshop_operator' => $this->site]);
        $payload = $this->payload([$this->lot('10')]);
        $this->withToken($this->tokenFor($operator, $this->industrial))->postJson('/api/v1/pickups', $payload)->assertForbidden();
    }

    /**
     * @param  list<string>  $lots
     * @return array<string, mixed>
     */
    private function payload(array $lots): array
    {
        return ['site' => $this->site->ulid, 'provider' => $this->provider->ulid, 'requested_date' => now()->addDay()->toDateString(), 'lots' => $lots];
    }

    private function lot(string $kg): string
    {
        return $this->tenant()->runAs($this->industrial, function () use ($kg) {
            $zone = Zone::query()->firstOrCreate(['code' => 'AIRE'], ['site_id' => $this->site->id, 'zone_type_id' => DB::table('zone_types')->value('id'), 'name' => 'Aire']);
            $type = WasteType::query()->firstOrCreate(['code' => 'CHUTES'], ['name' => 'Chutes', 'waste_family_id' => DB::table('waste_families')->value('id'),
                'default_unit_id' => DB::table('units')->value('id'), 'activated_at' => now()]);

            return app(CreateWasteLot::class)->handle(new CreateWasteLotData(
                $this->site->ulid, $zone->ulid, $type->ulid, $kg), $this->manager)->ulid;
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
