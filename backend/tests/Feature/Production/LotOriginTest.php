<?php

namespace Tests\Feature\Production;

use App\Modules\Catalog\Models\WasteType;
use App\Modules\Identity\Models\User;
use App\Modules\Sites\Models\Site;
use App\Modules\Sites\Models\Zone;
use App\Modules\Tenancy\Models\Company;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * A waste lot says where it comes from: its manufacturing order, the stage, the raw material and the delivery. The
 * operator chooses the OF; the rest is deduced, and the lot takes the composition and colour of its material.
 */
class LotOriginTest extends TestCase
{
    use InteractsWithTenants;

    private Company $company;

    private Site $site;

    private User $admin;

    private WasteType $type;

    private Zone $cuttingZone;

    /** @var array<string, mixed> */
    private array $of;

    /** @var array<string, mixed> */
    private array $material;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([ReferenceDataSeeder::class, AuthorizationSeeder::class]);
        $this->company = $this->makeCompany();
        $this->site = $this->makeSite($this->company, 'NAB');
        $this->admin = $this->makeMember($this->company, ['client_admin' => null]);
        [$this->type, $this->cuttingZone] = $this->tenant()->runAs($this->company, fn () => [
            WasteType::query()->create(['code' => 'CHUTES', 'name' => 'Chutes', 'waste_family_id' => DB::table('waste_families')->value('id'),
                'default_unit_id' => DB::table('units')->where('code', 'kg')->value('id'), 'activated_at' => now(), 'default_composition' => [['material' => 'PES', 'pct' => '100']]]),
            Zone::query()->create(['site_id' => $this->site->id, 'zone_type_id' => DB::table('zone_types')->where('code', 'cutting')->value('id'), 'code' => 'COUPE', 'name' => 'Atelier de coupe']),
        ]);

        $client = $this->client()->postJson('/api/v1/production/clients', ['code' => 'LEV', 'name' => 'Levant Denim'])->assertCreated()->json('data');
        $supplier = $this->client()->postJson('/api/v1/production/suppliers', ['code' => 'MIL', 'name' => 'Denim Mills'])->assertCreated()->json('data');
        $this->material = $this->client()->postJson('/api/v1/production/materials', ['reference' => 'DEN-12OZ', 'name' => 'Denim', 'supplier' => $supplier['ulid'], 'unit' => 'm',
            'color_label' => '19-4052 TCX', 'composition' => [['material' => 'CO', 'pct' => '98'], ['material' => 'EL', 'pct' => '2']]])->assertCreated()->json('data');
        $this->material = $this->client()->postJson("/api/v1/production/materials/{$this->material['ulid']}/batches", ['batch_number' => 'R-0412'])->assertCreated()->json('data');
        $article = $this->client()->postJson('/api/v1/production/articles', ['reference' => 'JEAN-501', 'name' => 'Jean', 'client' => $client['ulid'],
            'materials' => [['material' => $this->material['ulid'], 'quantity_per_piece' => '1.35']]])->assertCreated()->json('data');
        $order = $this->client()->postJson('/api/v1/production/orders', ['order_number' => 'CMD-88', 'client' => $client['ulid'], 'lines' => [['article' => $article['ulid'], 'quantity' => 1000]]])->assertCreated()->json('data');
        $this->of = $this->client()->postJson('/api/v1/production/manufacturing-orders', ['order_number' => 'OF-14', 'article' => $article['ulid'], 'customer_order' => $order['ulid'],
            'quantity_planned' => 1000, 'status' => 'in_progress', 'steps' => [['step' => 'cutting', 'site' => $this->site->ulid]]])->assertCreated()->json('data');
    }

    public function test_choosing_the_manufacturing_order_is_enough(): void
    {
        $lot = $this->client()->postJson('/api/v1/lots', ['site' => $this->site->ulid, 'zone' => $this->cuttingZone->ulid, 'waste_type' => $this->type->ulid,
            'net_weight_kg' => '42', 'production_order' => $this->of['ulid']])->assertCreated()->json('data');

        // The whole chain is known: order, client, article; the stage from the zone; the material from the OF, and its supplier.
        $this->assertSame(['OF-14', 'CMD-88', 'Levant Denim', 'JEAN-501', 'cutting', 'DEN-12OZ', 'Denim Mills', null], [
            $lot['origin']['production_order']['order_number'], $lot['origin']['customer_order'], $lot['origin']['client'], $lot['origin']['article']['reference'],
            $lot['origin']['step']['code'], $lot['origin']['raw_material']['reference'], $lot['origin']['supplier'], $lot['origin']['batch'],
        ]);
        // The lot is made of what its material is made of, not of the default of the waste type.
        $this->assertSame('19-4052 TCX', $lot['color_label']);
        $this->client()->getJson("/api/v1/lots/{$lot['ulid']}")->assertOk()->assertJsonPath('meta.composition.0.material', 'CO')->assertJsonPath('meta.composition.0.pct', '98.00');

        $this->client()->getJson('/api/v1/lots?filter[production_order]='.$this->of['ulid'])->assertOk()->assertJsonCount(1, 'data');

        // A split keeps the origin; a group keeps it only where every lot agrees.
        $children = $this->client()->postJson("/api/v1/lots/{$lot['ulid']}/split", ['outputs' => [['net_weight_kg' => '30'], ['net_weight_kg' => '12']]])->assertCreated()->json('data');
        $this->assertSame(['OF-14', 'OF-14'], array_map(fn ($child) => $child['origin']['production_order']['order_number'], $children));
        $elsewhere = $this->client()->postJson('/api/v1/lots', ['site' => $this->site->ulid, 'zone' => $this->cuttingZone->ulid, 'waste_type' => $this->type->ulid, 'net_weight_kg' => '8'])->assertCreated()->json('data');
        $group = $this->client()->postJson('/api/v1/lots/group', ['lots' => [$children[0]['ulid'], $elsewhere['ulid']], 'zone' => $this->cuttingZone->ulid])->assertCreated()->json('data');
        $this->assertNull($group['origin']['production_order']);
        $this->assertSame('cutting', $group['origin']['step']['code']);
    }

    public function test_a_lot_may_only_know_its_stage_and_its_origin_can_be_corrected(): void
    {
        // No OF given: the stage of the zone is still recorded, and nothing else is invented.
        $lot = $this->client()->postJson('/api/v1/lots', ['site' => $this->site->ulid, 'zone' => $this->cuttingZone->ulid, 'waste_type' => $this->type->ulid, 'net_weight_kg' => '15'])
            ->assertCreated()->json('data');
        $this->assertSame('cutting', $lot['origin']['step']['code']);
        $this->assertNull($lot['origin']['production_order']);
        $this->assertNull($lot['origin']['raw_material']);

        // A zone that stands for no stage, no OF: the lot says nothing about its origin.
        $storage = $this->tenant()->runAs($this->company, fn () => Zone::query()->where('site_id', $this->site->id)->where('id', '!=', $this->cuttingZone->id)->firstOrFail());
        $this->client()->postJson('/api/v1/lots', ['site' => $this->site->ulid, 'zone' => $storage->ulid, 'waste_type' => $this->type->ulid, 'net_weight_kg' => '5'])
            ->assertCreated()->assertJsonPath('data.origin', null);

        // Given afterwards, with the delivery; a delivery of another material is refused.
        $batch = $this->material['batches'][0]['ulid'];
        $this->client()->putJson("/api/v1/lots/{$lot['ulid']}/origin", ['production_order' => $this->of['ulid'], 'raw_material' => $this->material['ulid'], 'raw_material_batch' => $batch])
            ->assertOk()->assertJsonPath('data.origin.production_order.order_number', 'OF-14')->assertJsonPath('data.origin.batch.batch_number', 'R-0412');
        $other = $this->client()->postJson('/api/v1/production/materials', ['reference' => 'POP', 'name' => 'Popeline', 'unit' => 'm'])->assertCreated()->json('data');
        $this->client()->putJson("/api/v1/lots/{$lot['ulid']}/origin", ['raw_material' => $other['ulid'], 'raw_material_batch' => $batch])->assertUnprocessable();

        // Another company's OF cannot be named; an operator may attach a lot but not correct one.
        $foreign = $this->makeCompany();
        $site = $this->makeSite($foreign, 'XXX');
        // The token is taken last: anything saved after it would make the test's auth guard remember the previous user.
        $stranger = $this->tokenFor($this->makeMember($foreign, ['client_admin' => null]), $foreign);
        $this->withToken($stranger)->getJson('/api/v1/production/open-orders')->assertOk()->assertJsonCount(0, 'data');
        $this->withToken($stranger)->postJson('/api/v1/lots', ['site' => $site->ulid, 'zone' => 'x', 'waste_type' => 'x', 'net_weight_kg' => '1', 'production_order' => $this->of['ulid']])
            ->assertUnprocessable()->assertJsonStructure(['errors' => ['production_order']]);
        $operator = $this->tokenFor($this->makeMember($this->company, ['workshop_operator' => $this->site]), $this->company);
        $this->withToken($operator)->putJson("/api/v1/lots/{$lot['ulid']}/origin", ['production_order' => $this->of['ulid']])->assertForbidden();
    }

    public function test_the_workshop_application_receives_the_open_orders_without_clients_or_quantities(): void
    {
        $operator = $this->tokenFor($this->makeMember($this->company, ['workshop_operator' => $this->site]), $this->company);

        foreach (['/api/v1/production/open-orders' => 'data', '/api/v1/sync/bootstrap' => 'data.manufacturing_orders'] as $url => $path) {
            $orders = $this->withToken($operator)->getJson($url)->assertOk()->assertJsonCount(1, $path)->json($path);
            $this->assertSame(['ulid', 'order_number', 'article', 'steps', 'materials'], array_keys($orders[0]));
            $this->assertSame(['OF-14', 'JEAN-501', [['step' => 'cutting', 'site' => $this->site->ulid]], 'DEN-12OZ'],
                [$orders[0]['order_number'], $orders[0]['article']['reference'], $orders[0]['steps'], $orders[0]['materials'][0]['reference']]);
        }

        // A finished OF is no longer offered; an operator of another site does not see an OF that never comes to it.
        $this->client()->putJson("/api/v1/production/manufacturing-orders/{$this->of['ulid']}", ['order_number' => 'OF-14', 'article' => $this->of['article']['ulid'],
            'customer_order' => $this->of['customer_order']['ulid'], 'quantity_planned' => 1000, 'status' => 'completed'])->assertOk();
        $this->withToken($operator)->getJson('/api/v1/production/open-orders')->assertOk()->assertJsonCount(0, 'data');
    }

    private function client(): static
    {
        return $this->withToken($this->tokenFor($this->admin, $this->company));
    }
}
