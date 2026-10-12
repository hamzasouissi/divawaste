<?php

namespace Tests\Feature\Sites;

use App\Modules\Catalog\Models\WasteType;
use App\Modules\Sites\Models\Site;
use App\Modules\Tenancy\Models\Company;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * What a zone and a site can physically hold. The room of a zone is measured one way — packages sharing one room, or
 * volume — never both; its load in kilograms is a separate limit; the weight a site may hold counts every lot once.
 * A quantity nobody knows is named and never counted as zero, and none of these limits refuses a lot.
 */
class PhysicalCapacityTest extends TestCase
{
    use InteractsWithTenants;
    use RefreshDatabase;

    private Company $company;

    private Site $site;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([ReferenceDataSeeder::class, AuthorizationSeeder::class]);
        $this->company = $this->makeCompany();
        $this->site = $this->makeSite($this->company, 'SFX');
        $this->token = $this->tokenFor($this->makeMember($this->company, ['client_admin' => null]), $this->company);
    }

    public function test_the_room_of_a_zone_measured_in_volume_adds_what_each_lot_takes(): void
    {
        $this->api()->putJson('/api/v1/packaging-volumes', ['volumes' => [['packaging_type' => 'bag', 'volume_m3' => '0.1'], ['packaging_type' => 'box', 'volume_m3' => '0.25']]])
            ->assertOk()->assertJsonFragment(['packaging_type' => 'bag', 'volume_m3' => '0.1000'])->assertJsonFragment(['packaging_type' => 'drum', 'volume_m3' => null]);
        $type = $this->type('CHUTES');
        $dense = $this->type('BOUES', density: '200');

        // Volume needs a volume to compare with.
        $this->api()->postJson("/api/v1/sites/{$this->site->ulid}/zones", ['code' => 'V0', 'name' => 'Sans volume', 'space_method' => 'volume'])->assertStatus(422)->assertJsonValidationErrors('capacity_m3');
        // 20 m³, 2,000 kg. The packages-per-packaging figures stay recorded but are not what measures this zone.
        $zone = $this->api()->postJson("/api/v1/sites/{$this->site->ulid}/zones", ['code' => 'V1', 'name' => 'Aire couverte', 'space_method' => 'volume', 'capacity_m3' => '20',
            'capacity_kg' => '2000', 'capacity_alert_pct' => 80, 'capacities' => [['packaging_type' => 'bag', 'max_count' => 10]]])
            ->assertCreated()->assertJsonPath('data.space_method', 'volume')->assertJsonPath('data.fill.constraints.0.method', 'volume')->json('data.ulid');
        $fill = fn () => $this->fill($zone);

        // 100 bags of 0.1 m³: 10 m³, half the room. By packages (10 bags) it would be ten times full: not evaluated.
        $this->lot($zone, $type, '500', ['packaging_type' => 'bag', 'package_count' => 100]);
        $first = $fill();
        $this->assertSame(['space', 'load'], array_keys($first['limits']));
        $this->assertEquals(['m3', '10.000', '20.000', 50, 'respected', '10.000', false], [$first['limits']['space']['unit'], $first['limits']['space']['used'], $first['limits']['space']['max'],
            $first['limits']['space']['pct'], $first['limits']['space']['outcome'], $first['limits']['space']['remaining'], isset($first['limits']['space']['lines'])]);

        // 20 boxes (5 m³), loose waste measured as 2,000 litres (2 m³), loose waste of 400 kg at 200 kg/m³ (2 m³): 19 m³.
        $this->lot($zone, $type, '200', ['packaging_type' => 'box', 'package_count' => 20]);
        $this->lot($zone, $type, null, ['quantity' => '2000', 'unit' => 'l']);
        $this->lot($zone, $dense, '400');
        $second = $fill();
        $this->assertEquals(['19.000', 95, 'warning'], [$second['limits']['space']['used'], $second['limits']['space']['pct'], $second['limits']['space']['outcome']]);
        // What takes the room, line by line, so the total can be checked by eye.
        $this->assertSame([['bag', 100, '10.000'], ['box', 20, '5.000'], ['', null, '4.000']], array_map(fn (array $line) => [$line['packaging_type'], $line['packages'], $line['volume_m3']], $second['volume']['lines']));
        // The load is its own limit: 1,100 kg known of 2,000, and one lot has no weight.
        $this->assertSame(['unverifiable', '1100.000', 1], [$second['limits']['load']['outcome'], $second['limits']['load']['used'], $second['limits']['load']['missing_count']]);

        // Drums have no volume on record, and loose waste with neither a measured volume nor a density has none either:
        // the room is no longer verified, and both lots are named. They are not sized by guess.
        $drums = $this->lot($zone, $type, '30', ['packaging_type' => 'drum', 'package_count' => 2])->json('data.lot_number');
        $loose = $this->lot($zone, $type, '40')->json('data.lot_number');
        $third = $fill();
        $this->assertSame(['unverifiable', '19.000', [$drums, $loose]], [$third['limits']['space']['outcome'], $third['limits']['space']['used'], $third['limits']['space']['missing_lots']]);

        // 20 more bags: 21 m³ known, above the 20 m³ — exceeded by 1 m³, the two lots still named. The lot was placed.
        $this->lot($zone, $type, '100', ['packaging_type' => 'bag', 'package_count' => 20]);
        $fourth = $fill();
        $this->assertSame(['exceeded', '21.000', '1.000', 2, 'exceeded'], [$fourth['limits']['space']['outcome'], $fourth['limits']['space']['used'], $fourth['limits']['space']['excess'], $fourth['limits']['space']['missing_count'], $fourth['outcome']]);

        // Once drums have a volume, they are counted: 2 × 0.2 m³.
        $this->api()->putJson('/api/v1/packaging-volumes', ['volumes' => [['packaging_type' => 'drum', 'volume_m3' => '0.2']]])->assertOk();
        $this->assertSame(['21.400', [$loose]], [$fill()['limits']['space']['used'], $fill()['limits']['space']['missing_lots']]);
        // And a volume that is withdrawn is unknown again, not zero.
        $this->api()->putJson('/api/v1/packaging-volumes', ['volumes' => [['packaging_type' => 'bag', 'volume_m3' => null]]])->assertOk();
        // Boxes 5 m³, loose 4 m³, drums 0.4 m³; the two lots of bags and the loose lot without density are named.
        $this->assertSame(['9.400', 3], [$fill()['limits']['space']['used'], $fill()['limits']['space']['missing_count']]);
    }

    public function test_a_zone_measures_its_room_one_way_and_the_other_way_is_not_mixed_in(): void
    {
        $this->api()->putJson('/api/v1/packaging-volumes', ['volumes' => [['packaging_type' => 'bag', 'volume_m3' => '0.1'], ['packaging_type' => 'box', 'volume_m3' => '0.2']]])->assertOk();
        $type = $this->type('CHUTES');
        $payload = ['code' => 'M1', 'name' => 'Quai', 'capacity_m3' => '10', 'capacity_alert_pct' => 90, 'capacities' => [['packaging_type' => 'bag', 'max_count' => 100], ['packaging_type' => 'box', 'max_count' => 50]]];
        // Left unsaid, the room is measured as before: packages per packaging, whatever volume is on record.
        $zone = $this->api()->postJson("/api/v1/sites/{$this->site->ulid}/zones", $payload)->assertCreated()->assertJsonPath('data.space_method', 'packaging')->json('data.ulid');
        $this->lot($zone, $type, '100', ['packaging_type' => 'bag', 'package_count' => 50]);
        $this->lot($zone, $type, '100', ['packaging_type' => 'box', 'package_count' => 25]);

        // 50 bags of 100 and 25 boxes of 50 share the room: 100 %, one limit.
        $byPackages = $this->fill($zone);
        $this->assertSame(['space'], array_keys($byPackages['limits']));
        $this->assertEquals(['packaging', 100, 'warning', 'package', null], [$byPackages['limits']['space']['method'], $byPackages['limits']['space']['pct'], $byPackages['limits']['space']['outcome'], $byPackages['limits']['space']['unit'], $byPackages['volume']]);

        // Measured in volume, the same lots take 5 + 5 = 10 m³ of 10: again one limit, in cubic metres this time.
        $this->api()->putJson("/api/v1/zones/{$zone}", ['space_method' => 'volume'] + $payload)->assertOk();
        $byVolume = $this->fill($zone);
        $this->assertSame(['space'], array_keys($byVolume['limits']));
        $this->assertEquals(['volume', 'm3', '10.000', '10.000', 100, 'warning'], [$byVolume['limits']['space']['method'], $byVolume['limits']['space']['unit'], $byVolume['limits']['space']['used'], $byVolume['limits']['space']['max'], $byVolume['limits']['space']['pct'], $byVolume['limits']['space']['outcome']]);
        // The packages are still shown for what they are.
        $this->assertSame(['bag' => 50, 'box' => 25], $byVolume['packages']);
    }

    public function test_the_weight_a_site_may_hold_counts_every_lot_once_whatever_its_zone(): void
    {
        $type = $this->type('CHUTES');
        $other = $this->type('FILS');
        $limited = $this->api()->postJson("/api/v1/sites/{$this->site->ulid}/zones", ['code' => 'A', 'name' => 'Atelier A', 'capacity_kg' => '2000'])->assertCreated()->json('data.ulid');
        $free = $this->api()->postJson("/api/v1/sites/{$this->site->ulid}/zones", ['code' => 'B', 'name' => 'Cour'])->assertCreated()->json('data.ulid');
        $site = fn () => $this->refreshed()->getJson("/api/v1/sites/{$this->site->ulid}")->assertOk()->json('data');

        // No capacity on the site: nothing is watched, and the weight is still given.
        $this->lot($limited, $type, '1000');
        $this->assertSame([[], null, '1000.000'], [$site()['load']['constraints'], $site()['load']['outcome'], $site()['load']['quantity_kg']]);

        // 3,000 kg, alert at 80 %.
        $this->api()->putJson("/api/v1/sites/{$this->site->ulid}", ['name' => $this->site->name, 'site_kind' => $this->site->site_kind, 'capacity_kg' => '3000', 'capacity_alert_pct' => 80])
            ->assertOk()->assertJsonPath('data.capacity_alert_pct', 80);
        // A zone without any limit still weighs on the site: 1,000 + 1,500 = 2,500 kg, two lots, 83 %.
        $this->lot($free, $other, '1500');
        $load = $site()['load'];
        $this->assertEquals(['site_load', 'kg', '2500.000', '3000.000', 83.3, 'warning', '500.000', false, 2], [$load['constraints'][0]['key'], $load['constraints'][0]['unit'], $load['constraints'][0]['used'],
            $load['constraints'][0]['max'], $load['constraints'][0]['pct'], $load['constraints'][0]['outcome'], $load['constraints'][0]['remaining'], $load['constraints'][0]['blocking'], $load['lots']]);
        // The zone has its own answer: 1,000 of 2,000 kg. Neither replaces the other.
        $this->assertEquals([50, 'respected'], [$this->fill($limited)['limits']['load']['pct'], $this->fill($limited)['limits']['load']['outcome']]);

        // A lot nobody weighed: the site can no longer be called within its capacity, and the lot is named.
        $unweighed = $this->lot($free, $type, null)->json('data.lot_number');
        $this->assertSame(['unverifiable', [$unweighed], 1, 3], [$site()['load']['constraints'][0]['outcome'], $site()['load']['constraints'][0]['missing_lots'], $site()['load']['unweighed_lots'], $site()['load']['lots']]);

        // 600 kg more: 3,100 kg known, exceeded by 100 kg, the unweighed lot still named. Nothing was refused.
        $this->lot($limited, $type, '600');
        $this->assertSame(['exceeded', '3100.000', '100.000', 1], [$site()['load']['constraints'][0]['outcome'], $site()['load']['constraints'][0]['used'], $site()['load']['constraints'][0]['excess'], $site()['load']['constraints'][0]['missing_count']]);

        // The stock page gives the same figure per site, whatever the filter applied to its rows.
        $stock = $this->refreshed()->getJson('/api/v1/stock?filter[waste_type]='.$other)->assertOk();
        $stock->assertJsonCount(1, 'data')->assertJsonCount(1, 'sites')->assertJsonPath('sites.0.site', $this->site->ulid)->assertJsonPath('sites.0.capacity_kg', '3000.000')
            ->assertJsonPath('sites.0.quantity_kg', '3100.000')->assertJsonPath('sites.0.lots', 4)->assertJsonPath('sites.0.constraints.0.outcome', 'exceeded');
        // A lot that leaves the site leaves its weight.
        DB::table('waste_lots')->where('net_weight_kg', '600.000')->update(['status' => 'collected']);
        $this->assertSame(['unverifiable', '2500.000'], [$site()['load']['constraints'][0]['outcome'], $site()['load']['constraints'][0]['used']]);
    }

    public function test_packaging_volumes_belong_to_the_company_and_are_set_by_those_who_manage_sites(): void
    {
        $this->api()->putJson('/api/v1/packaging-volumes', ['volumes' => [['packaging_type' => 'bag', 'volume_m3' => '0.12']]])->assertOk();
        $this->api()->putJson('/api/v1/packaging-volumes', ['volumes' => [['packaging_type' => 'bag', 'volume_m3' => '0']]])->assertStatus(422);
        $this->api()->putJson('/api/v1/packaging-volumes', ['volumes' => [['packaging_type' => 'nothing', 'volume_m3' => '1']]])->assertStatus(422);

        $this->refreshed()->getJson('/api/v1/packaging-volumes')->assertOk()->assertJsonFragment(['packaging_type' => 'bag', 'volume_m3' => '0.1200']);
        $this->assertSame(1, DB::table('packaging_volumes')->count());

        $operator = $this->tokenFor($this->makeMember($this->company, ['workshop_operator' => $this->site]), $this->company);
        $this->refreshed($operator)->putJson('/api/v1/packaging-volumes', ['volumes' => [['packaging_type' => 'bag', 'volume_m3' => '9']]])->assertForbidden();

        $elsewhere = $this->makeCompany();
        $this->makeSite($elsewhere, 'TUN');
        $foreign = $this->tokenFor($this->makeMember($elsewhere, ['client_admin' => null]), $elsewhere);
        $this->refreshed($foreign)->getJson('/api/v1/packaging-volumes')->assertOk()->assertJsonFragment(['packaging_type' => 'bag', 'volume_m3' => null]);
        $this->assertFalse(DB::table('packaging_volumes')->where('company_id', $elsewhere->id)->exists());
    }

    /**
     * @return array<string, mixed>
     */
    private function fill(string $zone): array
    {
        $fill = collect($this->refreshed()->getJson("/api/v1/sites/{$this->site->ulid}")->assertOk()->json('data.zones'))->firstWhere('ulid', $zone)['fill'];

        return $fill + ['limits' => array_column($fill['constraints'], null, 'key')];
    }

    private function type(string $code, ?string $density = null): string
    {
        return $this->tenant()->runAs($this->company, fn () => WasteType::query()->create(['code' => $code, 'name' => $code, 'waste_family_id' => DB::table('waste_families')->value('id'),
            'default_unit_id' => DB::table('units')->where('code', 'kg')->value('id'), 'density_kg_m3' => $density, 'activated_at' => now()])->ulid);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function lot(string $zone, string $type, ?string $kg, array $extra = []): TestResponse
    {
        return $this->api()->postJson('/api/v1/lots', ['site' => $this->site->ulid, 'zone' => $zone, 'waste_type' => $type, 'net_weight_kg' => $kg] + $extra)->assertCreated();
    }

    private function api(): static
    {
        return $this->withToken($this->token);
    }

    /** What a request has read is kept for that request only; the test runs several of them in one process. */
    private function refreshed(?string $token = null): static
    {
        $this->app->forgetScopedInstances();

        return $this->withToken($token ?? $this->token);
    }
}
