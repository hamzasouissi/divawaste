<?php

namespace Tests\Feature\Waste;

use App\Modules\Catalog\Models\WasteType;
use App\Modules\Notifications\BusinessEventNotification;
use App\Modules\Sites\Models\Site;
use App\Modules\Sites\Models\Zone;
use App\Modules\Tenancy\Models\Company;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * M1-01, M2-02, M2-04, M2-05, M2-07: zones, lot register, scan, label, weighing, stock and threshold alerts.
 */
class LotOperationsTest extends TestCase
{
    use InteractsWithTenants;

    private Company $company;

    private Site $site;

    private Zone $zone;

    private WasteType $type;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([ReferenceDataSeeder::class, AuthorizationSeeder::class]);

        $this->company = $this->makeCompany();
        $this->site = $this->makeSite($this->company, 'NAB');
        $this->zone = $this->tenant()->runAs($this->company, fn () => Zone::query()->where('site_id', $this->site->id)->firstOrFail());
        $this->type = $this->makeType($this->company, 'CHUTES', 'Chutes de coupe');
        $this->token = $this->tokenFor($this->makeMember($this->company, ['client_admin' => null]), $this->company);
    }

    public function test_zones_are_managed_per_site_and_a_zone_with_stock_cannot_be_closed(): void
    {
        $this->withToken($this->token)->getJson("/api/v1/sites/{$this->site->ulid}")->assertOk()
            ->assertJsonPath('data.zones.0.code', 'STOCK')->assertJsonPath('data.zones.0.is_waste_storage', true);

        $zone = $this->withToken($this->token)->postJson("/api/v1/sites/{$this->site->ulid}/zones", [
            'code' => 'COUPE', 'name' => 'Atelier de coupe', 'zone_type' => 'cutting', 'capacity_kg' => '500',
        ])->assertCreated()->assertJsonPath('data.zone_type', 'cutting')->json('data.ulid');

        $this->withToken($this->token)->postJson("/api/v1/sites/{$this->site->ulid}/zones", ['code' => 'COUPE', 'name' => 'x', 'zone_type' => 'cutting'])
            ->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->withToken($this->token)->putJson("/api/v1/zones/{$zone}", ['code' => 'COUPE', 'name' => 'Coupe 1', 'zone_type' => 'cutting'])
            ->assertOk()->assertJsonPath('data.name', 'Coupe 1');

        $this->createLot('40', $zone);
        $this->withToken($this->token)->postJson("/api/v1/zones/{$zone}/deactivate")->assertConflict()->assertJsonPath('code', 'ZONE_HAS_STOCK');
        $this->withToken($this->token)->postJson("/api/v1/sites/{$this->site->ulid}/deactivate")->assertConflict()->assertJsonPath('code', 'SITE_HAS_STOCK');
    }

    public function test_lot_register_is_filtered_sorted_and_paginated_on_the_server(): void
    {
        $other = $this->makeType($this->company, 'CARTON', 'Cartons');
        $a = $this->createLot('120.500');
        $b = $this->createLot('30', null, $other);
        $this->createLot('75.250');
        $this->withToken($this->token)->postJson("/api/v1/lots/{$b['ulid']}/store")->assertOk();

        $all = $this->withToken($this->token)->getJson('/api/v1/lots?sort=-net_weight_kg')->assertOk();
        $this->assertSame(['120.500', '75.250', '30.000'], array_column($all->json('data'), 'net_weight_kg'));
        $this->assertSame(3, $all->json('meta.total'));
        $this->assertSame('Chutes de coupe', $all->json('data.0.waste_type.name'));
        $this->assertSame('NAB', $all->json('data.0.site.code'));

        $this->assertCount(1, $this->withToken($this->token)->getJson('/api/v1/lots?filter[status][]=stored')->json('data'));
        $this->assertCount(2, $this->withToken($this->token)->getJson("/api/v1/lots?filter[waste_type]={$this->type->ulid}")->json('data'));
        $this->assertCount(1, $this->withToken($this->token)->getJson('/api/v1/lots?filter[search]='.$a['lot_number'])->json('data'));
        $this->assertCount(2, $this->withToken($this->token)->getJson('/api/v1/lots?per_page=2')->json('data'));
        $this->withToken($this->token)->getJson('/api/v1/lots?sort=company_id')->assertUnprocessable();

        // The register never runs one query per row.
        DB::enableQueryLog();
        $this->withToken($this->token)->getJson('/api/v1/lots')->assertOk();
        $this->assertLessThan(20, count(DB::getQueryLog()));
        DB::disableQueryLog();
    }

    public function test_scanning_a_label_finds_the_lot_and_the_label_is_a_100_by_50_mm_pdf(): void
    {
        $lot = $this->createLot('42.5');

        $this->withToken($this->token)->getJson("/api/v1/lots/scan/{$lot['qr']}")->assertOk()->assertJsonPath('data.ulid', $lot['ulid']);
        $this->withToken($this->token)->getJson('/api/v1/lots/scan/UNKNOWN-TAG')->assertNotFound();
        // The number printed on the label, typed by hand (any case), designates the same lot.
        $this->withToken($this->token)->getJson('/api/v1/lots/scan/'.strtolower($lot['lot_number']))->assertOk()->assertJsonPath('data.ulid', $lot['ulid']);

        $response = $this->withToken($this->token)->get("/api/v1/lots/{$lot['ulid']}/label")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $pdf = $response->getContent();
        $this->assertStringStartsWith('%PDF-', $pdf);
        // 100 mm × 50 mm in PDF points.
        $this->assertMatchesRegularExpression('/MediaBox \[0\.0+ 0\.0+ 283\.46\d* 141\.73\d*\]/', $pdf);
        // Exactly one label per print: a layout overflowing onto a second page would feed two labels.
        $this->assertMatchesRegularExpression('/\/Type \/Pages\b[^>]*\/Count 1\b/s', $pdf);

        $this->withToken($this->token)->get("/api/v1/lots/{$lot['ulid']}/label")->assertOk();
        $this->withToken($this->token)->getJson("/api/v1/lots/{$lot['ulid']}")->assertOk()->assertJsonPath('data.label_print_count', 2);
        $events = $this->withToken($this->token)->getJson("/api/v1/lots/{$lot['ulid']}/events")->assertOk()->json('data');
        $this->assertSame(['created', 'label_printed', 'label_printed'], array_column($events, 'event_type'));
        $this->assertNotNull($events[0]['actor']);

        // A tag of another company is unknown, not forbidden: its existence is not revealed.
        $foreign = $this->makeCompany();
        $foreignToken = $this->tokenFor($this->makeMember($foreign, ['client_admin' => null]), $foreign);
        $this->withToken($foreignToken)->getJson("/api/v1/lots/scan/{$lot['qr']}")->assertNotFound();
        $this->withToken($foreignToken)->getJson("/api/v1/lots/scan/{$lot['lot_number']}")->assertNotFound();
        $this->withToken($foreignToken)->get("/api/v1/lots/{$lot['ulid']}/label")->assertNotFound();
    }

    public function test_reweighing_keeps_the_previous_weight_in_history_and_is_refused_once_in_a_pickup_or_stale(): void
    {
        $lot = $this->createLot('50');

        $this->withToken($this->token)->postJson("/api/v1/lots/{$lot['ulid']}/weigh", ['net_weight_kg' => '48.200', 'version' => 99])
            ->assertConflict()->assertJsonPath('code', 'LOT_VERSION_CONFLICT');
        $this->withToken($this->token)->postJson("/api/v1/lots/{$lot['ulid']}/weigh", ['net_weight_kg' => '48.200', 'version' => $lot['version']])
            ->assertOk()->assertJsonPath('data.net_weight_kg', '48.200');

        $event = collect($this->withToken($this->token)->getJson("/api/v1/lots/{$lot['ulid']}/events")->json('data'))->firstWhere('event_type', 'weighed');
        $this->assertSame('50.000', $event['payload']['previous_weight_kg']);
        $this->assertDatabaseHas('audit_logs', ['auditable_type' => 'waste_lots', 'auditable_ulid' => $lot['ulid'], 'event' => 'updated']);

        DB::table('waste_lots')->where('ulid', $lot['ulid'])->update(['status' => 'awaiting_pickup']);
        $this->withToken($this->token)->postJson("/api/v1/lots/{$lot['ulid']}/weigh", ['net_weight_kg' => '10'])
            ->assertConflict()->assertJsonPath('code', 'LOT_NOT_WEIGHABLE');
    }

    public function test_stock_is_the_sum_of_lots_on_site_and_thresholds_alert_once_per_level(): void
    {
        Notification::fake();
        $manager = $this->makeMember($this->company, ['environment_manager' => $this->site]);
        $operator = $this->makeMember($this->company, ['workshop_operator' => $this->site]);
        $elsewhere = $this->makeMember($this->company, ['environment_manager' => $this->makeSite($this->company, 'SFX')]);

        $this->withToken($this->token)->postJson('/api/v1/stock-thresholds', [
            'site' => $this->site->ulid, 'waste_type' => $this->type->ulid, 'max_quantity_kg' => '100', 'warning_pct' => 80,
        ])->assertCreated()->assertJsonPath('data.alert_level', null);

        $this->createLot('50');
        Notification::assertNothingSent();

        $this->createLot('35');
        Notification::assertSentTo($manager, BusinessEventNotification::class, fn (BusinessEventNotification $n) => $n->type === 'stock.threshold_warning'
            && $n->params['quantity_kg'] === '85.000' && $n->companyId === $this->company->id);
        Notification::assertSentTo($operator, BusinessEventNotification::class);
        Notification::assertNotSentTo($elsewhere, BusinessEventNotification::class);

        // Still in the warning band: nobody is told again.
        Notification::fake();
        $this->createLot('5');
        Notification::assertNothingSent();

        $big = $this->createLot('20');
        Notification::assertSentTo($manager, BusinessEventNotification::class, fn (BusinessEventNotification $n) => $n->type === 'stock.threshold_exceeded');

        $stock = $this->withToken($this->token)->getJson('/api/v1/stock')->assertOk();
        $stock->assertJsonPath('meta.total_kg', '110.000')->assertJsonPath('data.0.quantity_kg', '110.000')->assertJsonPath('data.0.lots', 4)
            ->assertJsonPath('thresholds.0.alert_level', 'exceeded');

        // A lot that leaves the site leaves the stock, and the alert level follows.
        DB::table('waste_lots')->where('ulid', $big['ulid'])->update(['status' => 'collected']);
        $this->withToken($this->token)->getJson('/api/v1/stock')->assertJsonPath('meta.total_kg', '90.000')->assertJsonPath('thresholds.0.alert_level', 'warning');

        // Someone limited to another site sees none of this stock.
        $this->withToken($this->tokenFor($elsewhere, $this->company))->getJson('/api/v1/stock')->assertOk()->assertJsonCount(0, 'data');
    }

    /**
     * @return array<string, mixed>
     */
    private function createLot(string $weight, ?string $zoneUlid = null, ?WasteType $type = null): array
    {
        return $this->withToken($this->token)->postJson('/api/v1/lots', [
            'site' => $this->site->ulid, 'zone' => $zoneUlid ?? $this->zone->ulid,
            'waste_type' => ($type ?? $this->type)->ulid, 'net_weight_kg' => $weight,
        ])->assertCreated()->json('data');
    }

    private function makeType(Company $company, string $code, string $name): WasteType
    {
        return $this->tenant()->runAs($company, fn () => WasteType::query()->create([
            'code' => $code, 'name' => $name,
            'waste_family_id' => DB::table('waste_families')->where('code', 'textile_product')->value('id'),
            'default_unit_id' => DB::table('units')->where('code', 'kg')->value('id'),
            'activated_at' => now(),
        ]));
    }
}
