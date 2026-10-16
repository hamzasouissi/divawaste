<?php

namespace Tests\Feature\Sites;

use App\Modules\Catalog\Models\WasteType;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\BusinessEventNotification;
use App\Modules\Notifications\Support\NotificationCatalog;
use App\Modules\Sites\Models\Site;
use App\Modules\Tenancy\Models\Company;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * People are told when the room of a zone, its load or the weight of a site becomes a concern — once per change of
 * state, with what is known and what is missing — and a lot is never refused for it. A volume recorded on a zone
 * before the room could be measured in volume is kept, shown as not used, and can be put to use.
 */
class CapacityAlertsTest extends TestCase
{
    use InteractsWithTenants;
    use RefreshDatabase;

    private Company $company;

    private Site $site;

    private string $token;

    private User $manager;

    private string $type;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([ReferenceDataSeeder::class, AuthorizationSeeder::class]);
        $this->company = $this->makeCompany();
        $this->site = $this->makeSite($this->company, 'SFX');
        $this->token = $this->tokenFor($this->makeMember($this->company, ['client_admin' => null]), $this->company);
        $this->manager = $this->makeMember($this->company, ['environment_manager' => $this->site]);
        $this->type = $this->tenant()->runAs($this->company, fn () => WasteType::query()->create(['code' => 'CHUTES', 'name' => 'CHUTES', 'waste_family_id' => DB::table('waste_families')->value('id'),
            'default_unit_id' => DB::table('units')->where('code', 'kg')->value('id'), 'activated_at' => now()])->ulid);
    }

    public function test_the_room_and_the_load_of_a_zone_are_announced_once_per_change_of_state_and_never_refuse_a_lot(): void
    {
        Notification::fake();
        $elsewhere = $this->makeMember($this->company, ['environment_manager' => $this->makeSite($this->company, 'TUN')]);
        $zone = $this->api()->postJson("/api/v1/sites/{$this->site->ulid}/zones", ['code' => 'A', 'name' => 'Atelier A', 'capacity_kg' => '1000', 'capacity_alert_pct' => 80,
            'capacities' => [['packaging_type' => 'bag', 'max_count' => 100]]])->assertCreated()->json('data.ulid');

        // Half full: nothing to say.
        $this->lot($zone, '300', ['packaging_type' => 'bag', 'package_count' => 50]);
        Notification::assertNothingSent();

        // 85 bags of 100: the room reaches its alert. The load (600 kg of 1,000) does not.
        $this->lot($zone, '300', ['packaging_type' => 'bag', 'package_count' => 35]);
        $room = $this->told('space_warning');
        $this->assertCount(1, $room);
        $this->assertSame([$this->site->name.' · Atelier A', 'Atelier A', '85', '85 sur 100 Sac', 0, null], [$room[0]->params['scope'], $room[0]->params['zone'], $room[0]->params['pct'], $room[0]->params['detail'], $room[0]->params['missing'], $room[0]->params['lots']]);
        $this->assertCount(0, $this->told('load_warning'));
        // Those who see the stock of this site, not the people of another site.
        Notification::assertNotSentTo($elsewhere, BusinessEventNotification::class);

        // The same state, recalculated by another movement: nobody is told again.
        Notification::fake();
        $this->lot($zone, '10', ['packaging_type' => 'bag', 'package_count' => 2]);
        Notification::assertNothingSent();

        // A lot nobody weighed: the load can no longer be verified — an incomplete measure, not an exceedance.
        $unweighed = $this->lot($zone, null, ['packaging_type' => 'bag'])->json('data.lot_number');
        $load = $this->told('load_unverifiable');
        $this->assertCount(1, $load);
        $this->assertSame(['610 kg sur 1 000 kg', 1, $unweighed, null], [$load[0]->params['detail'], $load[0]->params['missing'], $load[0]->params['lots'], $load[0]->params['excess']]);
        $this->assertCount(0, $this->told('load_exceeded'));
        // The room is still at its alert: said once already.
        $this->assertCount(0, $this->told('space_warning'));

        // 500 kg of loose waste: placed all the same. The load is now exceeded on what is known (1,110 kg), the
        // unweighed lot still named; the room cannot count a loose lot and says so. Two limits, two messages.
        Notification::fake();
        $loose = $this->lot($zone, '500')->json('data.lot_number');
        $exceeded = $this->told('load_exceeded');
        $this->assertSame(['1 110 kg sur 1 000 kg', '110 kg', '111', 1, $unweighed], [$exceeded[0]->params['detail'], $exceeded[0]->params['excess'], $exceeded[0]->params['pct'], $exceeded[0]->params['missing'], $exceeded[0]->params['lots']]);
        $unverifiable = $this->told('space_unverifiable');
        $this->assertSame(['88 sur 100 Sac', '88', 1, $loose], [$unverifiable[0]->params['detail'], $unverifiable[0]->params['pct'], $unverifiable[0]->params['missing'], $unverifiable[0]->params['lots']]);

        // Over capacity, and lots are still accepted — with nothing new to announce.
        Notification::fake();
        $this->lot($zone, '50', ['packaging_type' => 'bag']);
        $this->lot($zone, '50');
        Notification::assertNothingSent();
        $this->assertEquals(['load' => 'exceeded', 'space' => 'unverifiable'], DB::table('capacity_alerts')->pluck('alert_level', 'capacity_type')->all());

        // Emails follow the stock limits: an exceedance and an incomplete measure are mailed, a warning is not.
        $this->assertSame([false, true, true, true, true], array_map(NotificationCatalog::sendsMail(...), ['capacity.space_warning', 'capacity.space_unverifiable', 'capacity.space_exceeded', 'capacity.load_exceeded', 'capacity.load_unverifiable']));

        // A limit that is removed has no state left to remember.
        $this->api()->putJson("/api/v1/zones/{$zone}", ['code' => 'A', 'name' => 'Atelier A', 'capacity_kg' => null, 'capacities' => []])->assertOk();
        $this->assertSame(0, DB::table('capacity_alerts')->count());
    }

    public function test_the_weight_of_a_whole_site_is_watched_by_a_stock_limit_on_the_site(): void
    {
        Notification::fake();
        $zone = $this->api()->postJson("/api/v1/sites/{$this->site->ulid}/zones", ['code' => 'C', 'name' => 'Cour'])->assertCreated()->json('data.ulid');
        // A site has no capacity of its own any more: what is sent for it is not kept, and its weight is still given.
        $this->api()->putJson("/api/v1/sites/{$this->site->ulid}", ['name' => $this->site->name, 'site_kind' => $this->site->site_kind, 'capacity_kg' => '1000'])->assertOk()
            ->assertJsonMissingPath('data.capacity_kg')->assertJsonPath('data.load.quantity_kg', '0.000');
        // The whole site — every zone, every waste type — is watched by a stock limit, here 1,000 kg.
        $this->api()->postJson('/api/v1/stock-thresholds', ['site' => $this->site->ulid, 'max_quantity' => '1000', 'unit' => 'kg', 'warning_pct' => 90])->assertCreated();
        $this->lot($zone, '1100');
        $exceeded = Notification::sent($this->manager, BusinessEventNotification::class, fn (BusinessEventNotification $n) => $n->type === 'stock.threshold_exceeded')->values();
        $this->assertCount(1, $exceeded);
        $this->assertSame($this->site->name, $exceeded[0]->params['site']);
        // No capacity alert is kept for the site itself.
        $this->assertSame(0, DB::table('capacity_alerts')->whereNull('zone_id')->count());
        $this->assertCount(0, Notification::sent($this->manager, BusinessEventNotification::class, fn (BusinessEventNotification $n) => str_starts_with($n->type, 'capacity.site')));
    }

    public function test_a_room_in_volume_is_unverifiable_until_the_volume_of_its_packaging_is_known(): void
    {
        Notification::fake();
        $zone = $this->api()->postJson("/api/v1/sites/{$this->site->ulid}/zones", ['code' => 'V', 'name' => 'Hall', 'space_method' => 'volume', 'capacity_m3' => '10', 'capacity_alert_pct' => 90])->assertCreated()->json('data.ulid');
        $bags = $this->lot($zone, '200', ['packaging_type' => 'bag', 'package_count' => 50])->json('data.lot_number');
        $unverifiable = $this->told('space_unverifiable');
        $this->assertSame(['0 m³ sur 10 m³', 1, $bags], [$unverifiable[0]->params['detail'], $unverifiable[0]->params['missing'], $unverifiable[0]->params['lots']]);

        // The company states what a bag takes: the zone is counted again at once — 9.5 m³ of 10, a verified warning.
        Notification::fake();
        $this->api()->putJson('/api/v1/packaging-volumes', ['volumes' => [['packaging_type' => 'bag', 'volume_m3' => '0.19']]])->assertOk();
        $warning = $this->told('space_warning');
        $this->assertSame(['9,5 m³ sur 10 m³', '95', 0], [$warning[0]->params['detail'], $warning[0]->params['pct'], $warning[0]->params['missing']]);
        // 0.21 m³ a bag: 10.5 m³, a confirmed exceedance by half a cubic metre.
        $this->api()->putJson('/api/v1/packaging-volumes', ['volumes' => [['packaging_type' => 'bag', 'volume_m3' => '0.21']]])->assertOk();
        $this->assertSame(['10,5 m³ sur 10 m³', '0,5 m³'], [$this->told('space_exceeded')[0]->params['detail'], $this->told('space_exceeded')[0]->params['excess']]);
    }

    public function test_a_volume_recorded_before_volumes_were_used_is_kept_shown_as_not_used_and_can_be_put_to_use(): void
    {
        // As left by the migration: a volume on record, the room measured in packages.
        $zone = $this->api()->postJson("/api/v1/sites/{$this->site->ulid}/zones", ['code' => 'L', 'name' => 'Ancienne aire', 'capacity_m3' => '12.5',
            'capacities' => [['packaging_type' => 'bag', 'max_count' => 100]]])->assertCreated()
            ->assertJsonPath('data.space_method', 'packaging')->assertJsonPath('data.capacity_m3', '12.500')->assertJsonPath('data.inactive_volume', true)
            ->assertJsonPath('data.fill.constraints.0.method', 'packaging')->json('data.ulid');
        $other = $this->api()->postJson("/api/v1/sites/{$this->site->ulid}/zones", ['code' => 'N', 'name' => 'Sans volume'])->assertCreated()->assertJsonPath('data.inactive_volume', false)->json('data.ulid');
        // Sites say how many of their zones are in this case.
        $this->fresh()->getJson('/api/v1/sites')->assertOk()->assertJsonPath('data.0.inactive_volume_zones', 1);

        // Editing the zone without touching the volume keeps it, still unused.
        $this->api()->putJson("/api/v1/zones/{$zone}", ['code' => 'L', 'name' => 'Ancienne aire (revue)', 'capacities' => [['packaging_type' => 'bag', 'max_count' => 120]]])
            ->assertOk()->assertJsonPath('data.capacity_m3', '12.500')->assertJsonPath('data.inactive_volume', true);

        // Whoever manages the zones decides to use it: the room is now measured in volume, the same 12.5 m³.
        $this->lot($zone, '40', ['packaging_type' => 'bag', 'package_count' => 10]);
        $this->api()->putJson('/api/v1/packaging-volumes', ['volumes' => [['packaging_type' => 'bag', 'volume_m3' => '0.25']]])->assertOk();
        $this->api()->putJson("/api/v1/zones/{$zone}", ['code' => 'L', 'name' => 'Ancienne aire (revue)', 'space_method' => 'volume', 'capacity_m3' => '12.5'])
            ->assertOk()->assertJsonPath('data.space_method', 'volume')->assertJsonPath('data.inactive_volume', false)
            ->assertJsonPath('data.fill.constraints.0.method', 'volume')->assertJsonPath('data.fill.constraints.0.used', '2.500')->assertJsonPath('data.fill.constraints.0.max', '12.500');
        $this->fresh()->getJson('/api/v1/sites')->assertOk()->assertJsonPath('data.0.inactive_volume_zones', 0);
        // Someone who cannot manage zones cannot switch one.
        $operator = $this->tokenFor($this->makeMember($this->company, ['workshop_operator' => $this->site]), $this->company);
        $this->fresh($operator)->putJson("/api/v1/zones/{$other}", ['code' => 'N', 'name' => 'Sans volume', 'space_method' => 'volume', 'capacity_m3' => '5'])->assertForbidden();
    }

    /**
     * @return Collection<int, BusinessEventNotification>
     */
    private function told(string $kind): Collection
    {
        return Notification::sent($this->manager, BusinessEventNotification::class, fn (BusinessEventNotification $n) => $n->type === 'capacity.'.$kind)->values();
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function lot(string $zone, ?string $kg, array $extra = []): TestResponse
    {
        return $this->api()->postJson('/api/v1/lots', ['site' => $this->site->ulid, 'zone' => $zone, 'waste_type' => $this->type, 'net_weight_kg' => $kg] + $extra)->assertCreated();
    }

    private function api(): static
    {
        return $this->withToken($this->token);
    }

    private function fresh(?string $token = null): static
    {
        $this->app->forgetScopedInstances();

        return $this->withToken($token ?? $this->token);
    }
}
