<?php

namespace Tests\Feature\Waste;

use App\Modules\Catalog\Models\WasteType;
use App\Modules\Common\Exceptions\BusinessRuleViolation;
use App\Modules\Identity\Models\User;
use App\Modules\Sites\Models\Site;
use App\Modules\Sites\Models\Zone;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Waste\Models\WasteLot;
use App\Modules\Waste\Models\WasteLotEvent;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class WasteLotTest extends TestCase
{
    use InteractsWithTenants;
    use RefreshDatabase;

    private Company $company;

    private Site $site;

    private Zone $zone;

    private WasteType $type;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([ReferenceDataSeeder::class, AuthorizationSeeder::class]);
        $this->company = $this->makeCompany();
        $this->site = $this->makeSite($this->company, 'SFX');
        [$this->zone, $this->type] = $this->tenant()->runAs($this->company, fn () => [
            Zone::query()->create(['site_id' => $this->site->id, 'zone_type_id' => DB::table('zone_types')->value('id'), 'code' => 'COUPE', 'name' => 'Coupe']),
            WasteType::query()->create(['code' => 'CHUTES', 'name' => 'Chutes', 'waste_family_id' => DB::table('waste_families')->value('id'),
                'default_unit_id' => DB::table('units')->value('id'), 'activated_at' => now(), 'is_hazardous' => false,
                'default_composition' => [['material' => 'CO', 'pct' => '100.00']]]),
        ]);
        $this->operator = $this->makeMember($this->company, ['workshop_operator' => $this->site]);
    }

    public function test_create_lot_with_number_tag_composition_and_event(): void
    {
        $ulid = '01JC2Z8M4V7H3K9P2RQW5X6Y8Z';
        $this->createLot(['ulid' => $ulid, 'net_weight_kg' => '184.5', 'gross_weight_kg' => '185.7', 'tare_weight_kg' => '1.2',
            'composition' => [['material' => 'CO', 'pct' => 80], ['material' => 'PES', 'pct' => 20]]])
            ->assertCreated()
            ->assertJsonPath('data.ulid', $ulid)
            ->assertJsonPath('data.lot_number', 'L-'.now()->format('Y').'-000001')
            ->assertJsonPath('data.qr', $ulid)
            ->assertJsonPath('data.status', 'created')
            ->assertJsonPath('data.composition_label', 'CO 80% / PES 20%');

        $event = DB::table('waste_lot_events')->first(['event_type', 'to_status', 'weight_kg', 'gross_weight_kg', 'user_id']);
        $this->assertSame(['created', 'created', '184.500', '185.700', $this->operator->id],
            [$event->event_type, $event->to_status, $event->weight_kg, $event->gross_weight_kg, $event->user_id]);
    }

    public function test_composition_defaults_from_type_and_must_total_100(): void
    {
        $this->createLot()->assertCreated()->assertJsonPath('data.composition_label', 'CO 100%');
        $this->createLot(['composition' => [['material' => 'CO', 'pct' => 50]]])->assertStatus(422);
    }

    public function test_device_token_records_device_reference(): void
    {
        $device = $this->withToken($this->tokenFor($this->operator, $this->company))
            ->postJson('/api/v1/mobile/devices', ['device' => '01JC2Z8M4W3V9R6T1QXK5B7N2D', 'name' => 'Tablette'])->json('data.token');
        app('auth')->forgetGuards();

        $this->withToken($device)->postJson('/api/v1/lots', $this->payload())->assertCreated();

        $this->assertSame(DB::table('devices')->value('id'), DB::table('waste_lots')->value('created_device_id'));
        $this->assertSame('mobile', DB::table('waste_lot_events')->value('source'));
    }

    public function test_qr_values_are_unique_forever_and_one_active_per_lot(): void
    {
        $this->createLot(['tag' => 'PRE-PRINTED-1'])->assertCreated();
        $this->createLot(['tag' => 'PRE-PRINTED-1'])->assertStatus(422);

        $this->expectException(QueryException::class);
        DB::table('lot_tags')->insert(['company_id' => $this->company->id, 'waste_lot_id' => DB::table('waste_lots')->value('id'),
            'tag_type' => 'qr', 'tag_value' => 'SECOND-ACTIVE', 'is_active_flag' => true]);
    }

    public function test_lifecycle_store_then_invalid_transition(): void
    {
        $lot = $this->createLot()->json('data.ulid');
        $token = $this->tokenFor($this->operator, $this->company);

        $this->withToken($token)->postJson("/api/v1/lots/{$lot}/store")->assertOk()
            ->assertJsonPath('data.status', 'stored')->assertJsonPath('data.version', 2);
        $this->withToken($token)->postJson("/api/v1/lots/{$lot}/store")->assertStatus(409)->assertJsonPath('code', 'INVALID_TRANSITION');
    }

    public function test_split_closes_parent_and_records_lineage(): void
    {
        $parent = $this->createLot(['net_weight_kg' => '100'])->json('data.ulid');

        $children = $this->withToken($this->tokenFor($this->operator, $this->company))
            ->postJson("/api/v1/lots/{$parent}/split", ['outputs' => [['net_weight_kg' => '60'], ['net_weight_kg' => '40']]])
            ->assertCreated()->assertJsonCount(2, 'data')->json('data');

        $this->assertSame('split', $children[0]['origin_type']);
        $this->assertSame('CO 100%', $children[0]['composition_label']);
        $this->assertNotSame($children[0]['qr'], $children[1]['qr']);
        $this->assertSame(['closed', 'split'], array_values((array) DB::table('waste_lots')->where('ulid', $parent)->first(['status', 'closed_reason'])));
        $this->assertSame(2, DB::table('waste_lot_lineage')->where('relation_type', 'split')->count());

        // >2 % weight gap without comment is refused.
        $other = $this->createLot(['net_weight_kg' => '100'])->json('data.ulid');
        $this->withToken($this->tokenFor($this->operator, $this->company))
            ->postJson("/api/v1/lots/{$other}/split", ['outputs' => [['net_weight_kg' => '50'], ['net_weight_kg' => '40']]])
            ->assertStatus(409)->assertJsonPath('code', 'SPLIT_WEIGHT_GAP');
    }

    public function test_grouping_creates_lot_with_weighted_composition_and_closes_inputs(): void
    {
        $a = $this->createLot(['net_weight_kg' => '75', 'composition' => [['material' => 'CO', 'pct' => 100]]])->json('data.ulid');
        $b = $this->createLot(['net_weight_kg' => '25', 'composition' => [['material' => 'PES', 'pct' => 100]]])->json('data.ulid');

        $this->withToken($this->tokenFor($this->operator, $this->company))
            ->postJson('/api/v1/lots/group', ['lots' => [$a, $b], 'zone' => $this->zone->ulid])
            ->assertCreated()
            ->assertJsonPath('data.origin_type', 'grouping')
            ->assertJsonPath('data.status', 'stored')
            ->assertJsonPath('data.net_weight_kg', '100.000')
            ->assertJsonPath('data.composition_label', 'CO 75% / PES 25%');

        $this->assertSame(2, DB::table('waste_lots')->where('closed_reason', 'grouped')->count());
        $this->assertSame(2, DB::table('waste_lot_lineage')->where('relation_type', 'grouping')->count());

        // An already grouped (closed) lot cannot be transformed again.
        $this->withToken($this->tokenFor($this->operator, $this->company))
            ->postJson('/api/v1/lots/group', ['lots' => [$a, $b], 'zone' => $this->zone->ulid])->assertStatus(409);
    }

    public function test_events_and_lineage_are_append_only_and_lots_never_physically_deleted(): void
    {
        $this->createLot();
        $this->tenant()->runAs($this->company, function () {
            $event = WasteLotEvent::query()->firstOrFail();
            try {
                $event->delete();
                $this->fail('Event deleted');
            } catch (BusinessRuleViolation) {
            }

            $this->expectException(BusinessRuleViolation::class);
            WasteLot::query()->firstOrFail()->forceDelete();
        });
    }

    public function test_lots_are_isolated_between_companies_and_sites(): void
    {
        $lot = $this->createLot()->json('data.ulid');

        $other = $this->makeCompany();
        $stranger = $this->makeMember($other, ['client_admin' => null]);
        $this->withToken($this->tokenFor($stranger, $other))->getJson("/api/v1/lots/{$lot}")->assertNotFound();

        $otherSite = $this->makeSite($this->company, 'MON');
        $remote = $this->makeMember($this->company, ['workshop_operator' => $otherSite]);
        $this->withToken($this->tokenFor($remote, $this->company))->getJson("/api/v1/lots/{$lot}")->assertForbidden();

        $this->withToken($this->tokenFor($this->operator, $this->company))->getJson("/api/v1/lots/{$lot}/events")
            ->assertOk()->assertJsonPath('data.0.event_type', 'created');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return $overrides + ['site' => $this->site->ulid, 'zone' => $this->zone->ulid, 'waste_type' => $this->type->ulid, 'net_weight_kg' => '10'];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createLot(array $overrides = []): TestResponse
    {
        return $this->withToken($this->tokenFor($this->operator, $this->company))->postJson('/api/v1/lots', $this->payload($overrides));
    }
}
