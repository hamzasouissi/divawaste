<?php

namespace Tests\Feature\Pickups;

use App\Modules\Catalog\Models\WasteType;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\BusinessEventNotification;
use App\Modules\Providers\Models\ProviderAccreditation;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\Sites\Models\Site;
use App\Modules\Sites\Models\Zone;
use App\Modules\Tenancy\Models\Company;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\ProvisionalReferenceSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Scenario C of the takeover brief — M4-01 to M4-05 end to end, both parties.
 */
class PickupScenarioTest extends TestCase
{
    use InteractsWithTenants;

    private Company $industrial;

    private Company $provider;

    private Site $site;

    private User $manager;

    private User $providerUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([ReferenceDataSeeder::class, AuthorizationSeeder::class, ProvisionalReferenceSeeder::class]);
        Notification::fake();

        $this->industrial = $this->makeCompany();
        $this->site = $this->makeSite($this->industrial, 'SFX');
        $this->manager = $this->makeMember($this->industrial, ['environment_manager' => $this->site]);
        $this->provider = $this->makeProvider('recycling_recovery', now()->addYear());
        $this->providerUser = $this->makeMember($this->provider, ['provider_admin' => null]);
    }

    public function test_directory_shows_accreditation_state_and_blocks_providers_that_cannot_be_chosen(): void
    {
        $expired = $this->makeProvider('recycling_recovery', now()->subDay());
        $expiring = $this->makeProvider('collection_transport_hazardous', now()->addDays(12));
        $unpublished = $this->makeProvider('disposal', now()->addYear(), published: false);
        $admin = $this->tokenFor($this->makeMember($this->industrial, ['client_admin' => null]), $this->industrial);

        foreach ([$this->provider, $expired, $expiring] as $provider) {
            $this->withToken($admin)->postJson('/api/v1/provider-partnerships', ['provider' => $provider->ulid])->assertCreated();
        }

        $rows = collect($this->withToken($admin)->getJson('/api/v1/providers/directory')->assertOk()->json('data'))->keyBy('ulid');

        $this->assertFalse($rows->has($unpublished->ulid));
        $this->assertTrue($rows[$this->provider->ulid]['selectable']);
        $this->assertSame('valid', $rows[$this->provider->ulid]['accreditations'][0]['state']);
        $this->assertSame('expiring', $rows[$expiring->ulid]['accreditations'][0]['state']);
        $this->assertTrue($rows[$expiring->ulid]['selectable']);
        $this->assertTrue($rows[$expiring->ulid]['accepts_hazardous']);
        $this->assertFalse($rows[$expired->ulid]['selectable']);
        $this->assertSame('no_valid_accreditation', $rows[$expired->ulid]['blocked_reason']);

        // The API refuses what the screen greys out (M4-02).
        $lot = $this->lot('40');
        $pickup = $this->withToken($this->tokenFor($this->manager, $this->industrial))->postJson('/api/v1/pickups', [
            'site' => $this->site->ulid, 'provider' => $expired->ulid, 'requested_date' => now()->addDay()->toDateString(), 'lots' => [$lot],
        ])->assertCreated()->json('data.ulid');
        $this->withToken($this->tokenFor($this->manager, $this->industrial))->postJson("/api/v1/pickups/{$pickup}/submit")
            ->assertConflict()->assertJsonPath('code', 'PROVIDER_NOT_ELIGIBLE');

        // tokenFor() also resets the guard the test framework keeps between requests.
        $admin = $this->tokenFor($this->makeMember($this->industrial, ['client_admin' => null]), $this->industrial);
        $hazardous = $this->withToken($admin)->getJson('/api/v1/providers/directory?filter[hazardous]=1')->assertOk()->json('data');
        $this->assertSame([$expiring->ulid], array_column($hazardous, 'ulid'));
    }

    public function test_pickup_from_request_to_closure_with_notifications_variance_price_and_treatment(): void
    {
        $this->partner();
        $lotA = $this->lot('100');
        $lotB = $this->lot('60');
        $lotC = $this->lot('25');
        $industrial = fn () => $this->withToken($this->tokenFor($this->manager, $this->industrial));
        $provider = fn () => $this->withToken($this->tokenFor($this->providerUser, $this->provider));

        $pickup = $industrial()->postJson('/api/v1/pickups', [
            'site' => $this->site->ulid, 'provider' => $this->provider->ulid, 'requested_date' => now()->addDays(2)->toDateString(),
            'lots' => [$lotA, $lotB, $lotC],
        ])->assertCreated()->assertJsonPath('data.provider.legal_name', $this->provider->legal_name)->json('data');
        $ulid = $pickup['ulid'];
        $numbers = array_column($pickup['lots'], 'lot_number_snapshot');

        // A draft is invisible to the provider.
        $provider()->getJson('/api/v1/pickups')->assertOk()->assertJsonCount(0, 'data');

        $industrial()->postJson("/api/v1/pickups/{$ulid}/submit")->assertOk()->assertJsonPath('data.status', 'requested');
        Notification::assertSentTo($this->providerUser, BusinessEventNotification::class, fn (BusinessEventNotification $n) => $n->type === 'pickup.requested'
            && $n->params['lots'] === 3 && $n->companyId === $this->provider->id && $n->link === "/provider/pickups/{$ulid}");

        // The provider finds the request in its own list, with the site to go to but none of the industrial's lots.
        $listed = $provider()->getJson('/api/v1/pickups?filter[status][]=requested')->assertOk()->assertJsonCount(1, 'data')->json('data.0');
        $this->assertSame($this->industrial->legal_name, $listed['company']['legal_name']);
        $this->assertSame('SFX', $listed['site']['code']);
        $this->assertSame(3, $listed['lots_count']);
        $this->assertSame('185.000', $listed['declared_weight_kg']);
        $provider()->getJson("/api/v1/lots/{$lotA}")->assertNotFound();
        $provider()->getJson('/api/v1/lots')->assertForbidden();

        $provider()->postJson("/api/v1/pickups/{$ulid}/confirm", ['confirmed_date' => now()->addDays(3)->toDateString()])->assertOk();
        Notification::assertSentTo($this->manager, BusinessEventNotification::class, fn (BusinessEventNotification $n) => $n->type === 'pickup.confirmed');

        // Loading: lot C is not scanned and stays on site.
        $industrial()->postJson("/api/v1/pickups/{$ulid}/collect", ['lots' => [['lot' => $lotA], ['lot' => $lotB, 'departure_weight_kg' => '58.500']]])
            ->assertOk()->assertJsonPath('data.status', 'collected')->assertJsonPath('data.departure_weight_kg', '158.500');
        $industrial()->getJson("/api/v1/lots/{$lotC}")->assertJsonPath('data.status', 'stored');
        $industrial()->getJson('/api/v1/stock')->assertJsonPath('meta.total_kg', '25.000');

        // Reception: 100 kg announced, 91 kg received on lot A → 9 % off, beyond the 5 % tolerance.
        $received = $provider()->postJson("/api/v1/pickups/{$ulid}/receive", ['lots' => [
            ['lot_number' => $numbers[0], 'received_weight_kg' => '91'], ['lot_number' => $numbers[1], 'received_weight_kg' => '58.500'],
        ]])->assertOk()->assertJsonPath('data.status', 'received')->assertJsonPath('data.variance_flagged', true)->json('data');
        $this->assertTrue($received['lots'][0]['variance_flagged']);
        $this->assertFalse($received['lots'][1]['variance_flagged']);
        Notification::assertSentTo($this->manager, BusinessEventNotification::class, fn (BusinessEventNotification $n) => $n->type === 'pickup.variance_flagged');

        // Closing is refused until the discrepancy is acknowledged and the treatment declared.
        $industrial()->postJson("/api/v1/pickups/{$ulid}/complete")->assertConflict()->assertJsonPath('code', 'VARIANCE_NOT_ACKNOWLEDGED');
        $industrial()->postJson("/api/v1/pickups/{$ulid}/acknowledge-variance", ['comment' => 'Humidité à la pesée de départ'])->assertOk()
            ->assertJsonPath('data.variance_acknowledged', true);
        $industrial()->postJson("/api/v1/pickups/{$ulid}/complete")->assertConflict()->assertJsonPath('code', 'TREATMENT_MISSING');

        // M4-05: lot A sold 0.350 / kg on the received weight, lot B costs 12 flat.
        $priced = $industrial()->putJson("/api/v1/pickups/{$ulid}/pricing", ['lots' => [
            ['lot_number' => $numbers[0], 'direction' => 'sale', 'pricing_mode' => 'per_kg', 'unit_price' => '0.350'],
            ['lot_number' => $numbers[1], 'direction' => 'cost', 'pricing_mode' => 'flat', 'unit_price' => '12'],
        ]])->assertOk()->json('data');
        $this->assertSame('31.850', $priced['lots'][0]['net_amount']);
        $this->assertSame('-12.000', $priced['lots'][1]['net_amount']);
        $this->assertSame('19.850', $priced['net_amount']);
        $provider()->putJson("/api/v1/pickups/{$ulid}/pricing", ['lots' => []])->assertForbidden();

        $provider()->postJson("/api/v1/pickups/{$ulid}/treat", ['lots' => [
            ['lot_number' => $numbers[0], 'channel' => 'recycling'], ['lot_number' => $numbers[1], 'channel' => 'landfill'],
        ]])->assertOk()->assertJsonPath('data.lots.0.treatment_channel', 'recycling')->assertJsonPath('data.lots.0.line_status', 'treated');
        $industrial()->getJson("/api/v1/lots/{$lotA}")->assertJsonPath('data.status', 'treated');

        $industrial()->postJson("/api/v1/pickups/{$ulid}/complete")->assertOk()->assertJsonPath('data.status', 'completed');
        $detail = $industrial()->getJson("/api/v1/lots/{$lotA}")->assertJsonPath('data.status', 'closed')->assertJsonPath('data.closed_reason', 'completed')->json();
        $this->assertSame($pickup['pickup_number'], $detail['meta']['pickup']['pickup_number']);

        // M2-05: the whole life of the lot, each step with its time and author.
        $events = $industrial()->getJson("/api/v1/lots/{$lotA}/events")->json('data');
        $this->assertSame(['created', 'awaiting_pickup', 'collected', 'treated', 'closed'],
            array_values(array_filter(array_column($events, 'to_status'))));
        $this->assertNotContains(null, array_column($events, 'actor'));

        // M7-02: both parties' actions are in the industrial's audit trail, attributed to the right company.
        $this->assertDatabaseHas('audit_logs', ['company_id' => $this->industrial->id, 'auditable_type' => 'pickups', 'actor_user_id' => $this->providerUser->id, 'actor_company_id' => $this->provider->id]);
    }

    public function test_another_provider_and_another_industrial_cannot_reach_the_pickup(): void
    {
        $this->partner();
        $ulid = $this->withToken($this->tokenFor($this->manager, $this->industrial))->postJson('/api/v1/pickups', [
            'site' => $this->site->ulid, 'provider' => $this->provider->ulid, 'requested_date' => now()->addDay()->toDateString(), 'lots' => [$this->lot('10')],
        ])->assertCreated()->json('data.ulid');
        $this->withToken($this->tokenFor($this->manager, $this->industrial))->postJson("/api/v1/pickups/{$ulid}/submit")->assertOk();

        $otherProvider = $this->makeProvider('recycling_recovery', now()->addYear());
        $intruder = $this->tokenFor($this->makeMember($otherProvider, ['provider_admin' => null]), $otherProvider);
        $this->withToken($intruder)->getJson("/api/v1/pickups/{$ulid}")->assertNotFound();
        $this->withToken($intruder)->postJson("/api/v1/pickups/{$ulid}/confirm", ['confirmed_date' => now()->addDay()->toDateString()])->assertNotFound();
        $this->withToken($intruder)->getJson('/api/v1/pickups')->assertOk()->assertJsonCount(0, 'data');

        $otherIndustrial = $this->makeCompany();
        $stranger = $this->tokenFor($this->makeMember($otherIndustrial, ['client_admin' => null]), $otherIndustrial);
        $this->withToken($stranger)->getJson("/api/v1/pickups/{$ulid}")->assertNotFound();
        $this->withToken($stranger)->postJson("/api/v1/pickups/{$ulid}/cancel", ['reason' => 'x'])->assertNotFound();
        $this->withToken($stranger)->getJson('/api/v1/pickups')->assertOk()->assertJsonCount(0, 'data');

        // A colleague limited to another site does not see it either.
        $elsewhere = $this->makeMember($this->industrial, ['environment_manager' => $this->makeSite($this->industrial, 'TUN')]);
        $this->withToken($this->tokenFor($elsewhere, $this->industrial))->getJson('/api/v1/pickups')->assertOk()->assertJsonCount(0, 'data');
        $this->withToken($this->tokenFor($elsewhere, $this->industrial))->getJson("/api/v1/pickups/{$ulid}")->assertForbidden();
    }

    private function partner(): void
    {
        $this->withToken($this->tokenFor($this->makeMember($this->industrial, ['client_admin' => null]), $this->industrial))
            ->postJson('/api/v1/provider-partnerships', ['provider' => $this->provider->ulid])->assertCreated();
    }

    private function lot(string $weight): string
    {
        return $this->tenant()->runAs($this->industrial, function () use ($weight) {
            $type = WasteType::query()->firstOrCreate(['code' => 'CHUTES'], [
                'name' => 'Chutes de coupe', 'activated_at' => now(),
                'waste_family_id' => DB::table('waste_families')->where('code', 'textile_product')->value('id'),
                'default_unit_id' => DB::table('units')->where('code', 'kg')->value('id'),
            ]);
            $zone = Zone::query()->where('site_id', $this->site->id)->firstOrFail();
            $admin = $this->makeMember($this->industrial, ['client_admin' => null]);

            return $this->withToken($this->tokenFor($admin, $this->industrial))->postJson('/api/v1/lots', [
                'site' => $this->site->ulid, 'zone' => $zone->ulid, 'waste_type' => $type->ulid, 'net_weight_kg' => $weight,
            ])->assertCreated()->json('data.ulid');
        });
    }

    private function makeProvider(string $accreditationType, mixed $expiresOn, bool $published = true): Company
    {
        $provider = $this->makeCompany('provider');

        $this->tenant()->runAs($provider, function () use ($provider, $accreditationType, $expiresOn, $published) {
            ProviderProfile::query()->create(['is_recycler' => true, 'is_published' => $published, 'published_at' => now()]);
            $file = DB::table('stored_files')->insertGetId(['ulid' => strtoupper((string) Str::ulid()), 'company_id' => $provider->id, 'disk' => 'local',
                'path' => "tenants/{$provider->ulid}/agrement.pdf", 'original_name' => 'agrement.pdf', 'mime_type' => 'application/pdf', 'extension' => 'pdf',
                'size_bytes' => 1, 'checksum_sha256' => str_repeat('a', 64), 'purpose' => 'accreditations', 'created_at' => now(), 'updated_at' => now()]);
            ProviderAccreditation::query()->create([
                'accreditation_type_id' => DB::table('accreditation_types')->where('code', $accreditationType)->value('id'),
                'reference_number' => 'AG-'.$provider->id, 'valid_from' => now()->subYears(2), 'expires_on' => $expiresOn,
                'stored_file_id' => $file, 'review_status' => 'approved',
            ]);
        });

        return $provider;
    }
}
