<?php

namespace Tests\Feature\Waste;

use App\Modules\Catalog\Models\WasteType;
use App\Modules\Documents\Actions\ManifestWorkflow;
use App\Modules\Documents\Models\HazardousWasteManifest;
use App\Modules\Identity\Models\User;
use App\Modules\Providers\Models\ProviderAccreditation;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\Sites\Models\Site;
use App\Modules\Sites\Models\Zone;
use App\Modules\Tenancy\Models\Company;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * What a lot is made of, known without the raw materials or the production module: typed when the lot is created or
 * afterwards, said where it comes from, carried by the pickup and printed on the tracking slip.
 */
class LotCompositionTest extends TestCase
{
    use InteractsWithTenants;
    use RefreshDatabase;

    private Company $industrial;

    private Company $provider;

    private Site $site;

    private User $manager;

    private User $providerUser;

    private string $zone;

    private string $type;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([ReferenceDataSeeder::class, AuthorizationSeeder::class]);
        $this->industrial = $this->makeCompany();
        $this->provider = $this->makeCompany('provider');
        $this->site = $this->makeSite($this->industrial, 'SFX');
        $this->manager = $this->makeMember($this->industrial, ['environment_manager' => $this->site]);
        $this->providerUser = $this->makeMember($this->provider, ['provider_admin' => null]);
        [$this->zone, $this->type] = $this->tenant()->runAs($this->industrial, fn () => [
            Zone::query()->create(['code' => 'AIRE', 'site_id' => $this->site->id, 'zone_type_id' => DB::table('zone_types')->value('id'), 'name' => 'Aire'])->ulid,
            WasteType::query()->create(['code' => 'CHUTES', 'name' => 'Chutes', 'waste_family_id' => DB::table('waste_families')->value('id'),
                'default_unit_id' => DB::table('units')->where('code', 'kg')->value('id'), 'activated_at' => now(), 'default_composition' => [['material' => 'CO', 'pct' => '100']]])->ulid,
        ]);
        $this->tenant()->runAs($this->provider, function () {
            ProviderProfile::query()->create(['is_collector' => true, 'is_recycler' => true, 'is_published' => true, 'published_at' => now()]);
            $file = DB::table('stored_files')->insertGetId(['ulid' => strtoupper((string) Str::ulid()), 'company_id' => $this->provider->id, 'disk' => 's3',
                'path' => 'tenants/p/agrement.pdf', 'original_name' => 'agrement.pdf', 'mime_type' => 'application/pdf', 'extension' => 'pdf',
                'size_bytes' => 1, 'checksum_sha256' => str_repeat('a', 64), 'purpose' => 'accreditation', 'created_at' => now(), 'updated_at' => now()]);
            $type = DB::table('accreditation_types')->insertGetId(['country_id' => DB::table('countries')->value('id'), 'code' => 'TN_COLLECT', 'name' => '{}']);
            ProviderAccreditation::query()->create(['accreditation_type_id' => $type, 'reference_number' => 'AG-1', 'valid_from' => now()->subYear(),
                'expires_on' => now()->addYear(), 'stored_file_id' => $file, 'review_status' => 'approved']);
        });
        $this->withToken($this->tokenFor($this->makeMember($this->industrial, ['client_admin' => null]), $this->industrial))
            ->postJson('/api/v1/provider-partnerships', ['provider' => $this->provider->ulid])->assertCreated();
    }

    public function test_the_lot_says_where_its_composition_comes_from(): void
    {
        $typed = $this->create([['material' => 'CO', 'pct' => '60'], ['material' => 'PES', 'pct' => '40']]);
        $this->assertSame('entered', $typed['composition_source']);
        $this->assertSame('CO 60% / PES 40%', $typed['composition_label']);

        // Nothing typed: the defaults of the waste type, said as such so that someone checks them.
        $this->assertSame('waste_type', $this->create(null)['composition_source']);
    }

    public function test_the_composition_is_entered_on_the_lot_and_follows_its_pickup_until_it_leaves(): void
    {
        $lot = $this->create(null)['ulid'];
        $pickup = $this->asManager()->postJson('/api/v1/pickups', ['site' => $this->site->ulid, 'provider' => $this->provider->ulid,
            'requested_date' => now()->addDay()->toDateString(), 'lots' => [$lot]])->assertCreated()->assertJsonPath('data.lots.0.composition.0.material', 'CO')->json('data.ulid');

        $this->asManager()->putJson("/api/v1/lots/{$lot}/composition", ['composition' => [['material' => 'CO', 'pct' => '70'], ['material' => 'PES', 'pct' => '20']]])
            ->assertUnprocessable();
        $this->asManager()->putJson("/api/v1/lots/{$lot}/composition", ['composition' => [['material' => 'CO', 'pct' => '70'], ['material' => 'PES', 'pct' => '30']]])
            ->assertOk()->assertJsonPath('data.composition_label', 'CO 70% / PES 30%')->assertJsonPath('data.composition_source', 'entered');
        $this->assertSame('composition_changed', DB::table('waste_lot_events')->orderByDesc('id')->value('event_type'));
        $this->asManager()->getJson("/api/v1/pickups/{$pickup}")->assertJsonPath('data.lots.0.composition.1.pct', '30.00');

        $this->asManager()->postJson("/api/v1/pickups/{$pickup}/submit")->assertOk();
        $this->withToken($this->tokenFor($this->providerUser, $this->provider))
            ->postJson("/api/v1/pickups/{$pickup}/confirm", ['confirmed_date' => now()->addDay()->toDateString()])->assertOk();
        $this->asManager()->postJson("/api/v1/pickups/{$pickup}/collect", ['lots' => [['lot' => $lot]]])->assertOk();

        // The slip prints what each lot is made of.
        $this->asManager()->postJson("/api/v1/pickups/{$pickup}/manifests")->assertCreated();
        $payload = $this->tenant()->runAs($this->industrial, fn () => app(ManifestWorkflow::class)->payload(HazardousWasteManifest::query()->firstOrFail()));
        $this->assertEquals([['material' => 'CO', 'pct' => '70.00'], ['material' => 'PES', 'pct' => '30.00']], $payload['lots'][0]['composition']);

        // Once it has left, the composition stays the one it left with.
        $this->asManager()->putJson("/api/v1/lots/{$lot}/composition", ['composition' => []])->assertStatus(409)->assertJsonPath('code', 'LOT_COMPOSITION_FROZEN');
    }

    /**
     * @param  list<array{material: string, pct: string}>|null  $composition
     * @return array<string, mixed>
     */
    private function create(?array $composition): array
    {
        return $this->asManager()->postJson('/api/v1/lots', array_filter(['site' => $this->site->ulid, 'zone' => $this->zone, 'waste_type' => $this->type,
            'net_weight_kg' => '25', 'composition' => $composition]))->assertCreated()->json('data');
    }

    private function asManager(): static
    {
        return $this->withToken($this->tokenFor($this->manager, $this->industrial));
    }
}
