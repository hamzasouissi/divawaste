<?php

namespace Tests\Feature\Production;

use App\Modules\Catalog\Models\WasteType;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\Company;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Sizes and cutting on the OF: a grid of sizes chosen by the OF with its quantity of each size, then the spreads
 * (matelas) of its fabrics — length, layers planned then cut, efficiency, the laize of the rolls laid when it differs —
 * which give the fabric laid and the cutting waste expected, fabric by fabric and in all.
 */
class CuttingSpreadTest extends TestCase
{
    use InteractsWithTenants;

    private Company $company;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([ReferenceDataSeeder::class, AuthorizationSeeder::class]);
        $this->company = $this->makeCompany();
        $this->admin = $this->makeMember($this->company, ['client_admin' => null]);
        $this->tenant()->runAs($this->company, fn () => WasteType::query()->create(['code' => 'CUTTING_SCRAPS', 'name' => 'Chutes de coupe',
            'waste_family_id' => DB::table('waste_families')->value('id'), 'waste_catalog_item_id' => DB::table('waste_catalog_items')->where('code', 'cutting_scraps')->value('id'),
            'default_unit_id' => DB::table('units')->where('code', 'kg')->value('id'), 'activated_at' => now()]));
    }

    public function test_an_of_is_cut_spread_by_spread(): void
    {
        // The company's sizes, typed at once and kept in order; a grid is made of them, in that order, and reused.
        $this->client()->postJson('/api/v1/production/sizes', ['codes' => ['S', 'M', 'L', 'XL', 'S']])->assertCreated()->assertJsonCount(4, 'data')->assertJsonPath('data.3.code', 'XL');
        $this->client()->postJson('/api/v1/production/size-grids', ['name' => 'Enfant', 'sizes' => ['2A']])->assertUnprocessable();
        $grid = $this->client()->postJson('/api/v1/production/size-grids', ['name' => 'Adulte', 'sizes' => ['XL', 'S', 'M', 'L']])->assertCreated()->assertJsonPath('data.sizes', ['S', 'M', 'L', 'XL'])->json('data');
        $this->client()->postJson('/api/v1/production/size-grids', ['name' => 'Adulte', 'sizes' => ['S']])->assertUnprocessable();
        $this->client()->getJson('/api/v1/production/size-grids')->assertOk()->assertJsonPath('data.0.sizes', ['S', 'M', 'L', 'XL']);

        $fabric = $this->client()->postJson('/api/v1/production/materials', ['reference' => 'F22-G1', 'name' => 'Coton', 'unit' => 'm', 'width_cm' => '150', 'grammage_gsm' => '200'])->assertCreated()->json('data');
        $label = $this->client()->postJson('/api/v1/production/materials', ['reference' => 'LABEL', 'name' => 'Etiquette', 'unit' => 'piece'])->assertCreated()->json('data');
        $article = $this->client()->postJson('/api/v1/production/articles', ['reference' => 'IKA', 'name' => 'Veste', 'materials' => [
            ['material' => $fabric['ulid'], 'quantity_per_piece' => '1.5', 'efficiency_pct' => '82'], ['material' => $label['ulid'], 'quantity_per_piece' => '1'],
        ]])->assertCreated()->json('data');

        // The OF says its sizes: the pieces planned are their total; a size outside the grid is refused.
        $payload = ['order_number' => 'PO-18', 'article' => $article['ulid'], 'quantity_planned' => 1, 'size_grid' => $grid['ulid'],
            'sizes' => [['size' => 'S', 'quantity' => 20], ['size' => 'M', 'quantity' => 40], ['size' => 'L', 'quantity' => 40], ['size' => 'XL', 'quantity' => 20]]];
        $this->client()->postJson('/api/v1/production/manufacturing-orders', ['sizes' => [['size' => 'XXL', 'quantity' => 1]]] + $payload)->assertUnprocessable()->assertJsonStructure(['errors' => ['sizes']]);
        // Before any spread, the fabrics of the article are listed, with the efficiency a spread starts from.
        $of = $this->client()->postJson('/api/v1/production/manufacturing-orders', $payload)->assertCreated()
            ->assertJsonPath('data.quantity_planned', 120)->assertJsonPath('data.size_grid.name', 'Adulte')->assertJsonPath('data.sizes.1', ['size' => 'M', 'quantity' => 40])
            ->assertJsonCount(1, 'data.cutting.fabrics')->assertJsonPath('data.cutting.fabrics.0.material.reference', 'F22-G1')
            ->assertJsonPath('data.cutting.fabrics.0.article_m', '180.000')->assertJsonPath('data.cutting.fabrics.0.efficiency_pct', '82.00')
            ->assertJsonPath('data.cutting.fabrics.0.efficiency_from', 'article')->assertJsonPath('data.cutting.fabrics.0.spreads', [])
            ->assertJsonPath('data.cutting.totals.spreads', 0)->assertJsonPath('data.cutting.totals.planned', null)
            ->assertJsonPath('data.waste.estimated.source', 'article')->json('data');

        // A spread: 6.02 m, 20 layers planned, 82 %. 120.4 m × 1.5 m × 200 g = 36.12 kg of fabric; 18 % of it is 6.502 kg.
        $spread = ['material' => $fabric['ulid'], 'length_m' => '6.02', 'layers_planned' => 20, 'efficiency_pct' => '82'];
        $this->client()->postJson("/api/v1/production/manufacturing-orders/{$of['ulid']}/spreads", ['material' => $label['ulid']] + $spread)->assertUnprocessable()->assertJsonStructure(['errors' => ['material']]);
        $data = $this->client()->postJson("/api/v1/production/manufacturing-orders/{$of['ulid']}/spreads", $spread)->assertCreated()
            ->assertJsonPath('data.cutting.fabrics.0.spreads.0.planned', ['metres' => '120.400', 'fabric_kg' => '36.120', 'waste_kg' => '6.502'])
            ->assertJsonPath('data.cutting.fabrics.0.spreads.0.done', null)->assertJsonPath('data.cutting.fabrics.0.spreads.0.width_cm', '150.00')
            ->assertJsonPath('data.cutting.fabrics.0.spreads.0.own_width', false)->assertJsonPath('data.cutting.fabrics.0.waste_types.0.waste_type.name', 'Chutes de coupe')
            ->assertJsonPath('data.cutting.totals.planned.waste_pct', '18.0')->assertJsonPath('data.cutting.totals.spreads_to_cut', 1)
            ->assertJsonPath('data.waste.estimated.source', 'cutting')->assertJsonPath('data.waste.estimated.planned_kg', '6.502')
            ->assertJsonPath('data.waste.estimated.per_piece_kg', '0.0542')->assertJsonPath('data.waste.estimated.planned_pieces', 120)
            ->assertJsonPath('data.waste.estimated.produced_kg', null)->assertJsonPath('data.waste.estimated.by_type.0.planned_kg', '6.502')->json('data');
        $ulid = $data['cutting']['fabrics'][0]['spreads'][0]['ulid'];

        // 19 layers really cut: 114.38 m, 34.314 kg, 6.177 kg of waste; cut today unless told otherwise.
        $this->client()->putJson("/api/v1/production/spreads/{$ulid}", ['layers_done' => 19] + $spread)->assertOk()
            ->assertJsonPath('data.cutting.fabrics.0.spreads.0.done', ['metres' => '114.380', 'fabric_kg' => '34.314', 'waste_kg' => '6.177'])
            ->assertJsonPath('data.cutting.fabrics.0.spreads.0.cut_on', now()->toDateString())
            ->assertJsonPath('data.cutting.totals.done.waste_kg', '6.177')->assertJsonPath('data.cutting.totals.done.waste_pct', '18.0')
            ->assertJsonPath('data.cutting.totals.spreads_to_cut', 0)
            ->assertJsonPath('data.waste.estimated.produced_kg', '6.177')->assertJsonPath('data.waste.estimated.by_type.0.produced_kg', '6.177');

        // The rolls laid were 140 cm wide, not 150: 114.38 × 1.4 × 0.2 = 32.026 kg, 5.765 kg of waste.
        $this->client()->putJson("/api/v1/production/spreads/{$ulid}", ['layers_done' => 19, 'width_cm' => '140', 'cut_on' => '2026-11-10'] + $spread)->assertOk()
            ->assertJsonPath('data.cutting.fabrics.0.spreads.0.own_width', true)->assertJsonPath('data.cutting.fabrics.0.spreads.0.width_cm', '140.00')
            ->assertJsonPath('data.cutting.fabrics.0.spreads.0.done.waste_kg', '5.765')->assertJsonPath('data.cutting.fabrics.0.spreads.0.cut_on', '2026-11-10');
        // Back to the laize of the raw material: no longer the spread's own.
        $this->client()->putJson("/api/v1/production/spreads/{$ulid}", ['layers_done' => 19, 'width_cm' => '150'] + $spread)->assertOk()
            ->assertJsonPath('data.cutting.fabrics.0.spreads.0.own_width', false)->assertJsonPath('data.cutting.fabrics.0.spreads.0.cut_on', '2026-11-10');

        // An interlining the article does not list gets its own card, at the company's efficiency unless told: 2.1 m at 88 %,
        // 20 layers is 42 m × 1.5 m × 40 g = 2.52 kg, 0.302 kg of waste.
        $interlining = $this->client()->postJson('/api/v1/production/materials', ['reference' => 'VESLINE', 'name' => 'Entoilage', 'unit' => 'm', 'width_cm' => '150', 'grammage_gsm' => '40'])
            ->assertCreated()->json('data');
        $added = $this->client()->postJson("/api/v1/production/manufacturing-orders/{$of['ulid']}/spreads", ['material' => $interlining['ulid'], 'length_m' => '2.1', 'efficiency_pct' => '88'] + $spread)
            ->assertCreated()->assertJsonPath('data.cutting.fabrics.1.material.reference', 'VESLINE')->assertJsonPath('data.cutting.fabrics.1.in_article', false)
            ->assertJsonPath('data.cutting.fabrics.1.efficiency_from', 'company')->assertJsonPath('data.cutting.fabrics.1.planned.waste_kg', '0.302')
            ->assertJsonPath('data.cutting.totals.planned.waste_kg', '6.804')->json('data');
        $this->client()->deleteJson("/api/v1/production/spreads/{$added['cutting']['fabrics'][1]['spreads'][0]['ulid']}")->assertOk()->assertJsonCount(1, 'data.cutting.fabrics');

        // A size an OF gave quantities to cannot leave the grid; another can be added, once it is one of the company's.
        $this->client()->putJson("/api/v1/production/size-grids/{$grid['ulid']}", ['name' => 'Adulte', 'sizes' => ['S', 'M', 'L']])->assertUnprocessable();
        $sizes = $this->client()->postJson('/api/v1/production/sizes', ['codes' => ['XS']])->assertCreated()->json('data');
        $order = array_column($sizes, 'ulid');
        $this->client()->putJson('/api/v1/production/sizes/order', ['sizes' => array_merge([end($order)], array_slice($order, 0, -1))])->assertOk()->assertJsonPath('data.0.code', 'XS');
        $this->client()->putJson("/api/v1/production/size-grids/{$grid['ulid']}", ['name' => 'Adulte', 'sizes' => ['S', 'M', 'L', 'XL', 'XS']])->assertOk()
            ->assertJsonPath('data.sizes', ['XS', 'S', 'M', 'L', 'XL'])->assertJsonPath('data.orders', 1);
        // A size a grid uses stays; one nobody uses goes.
        $this->client()->deleteJson("/api/v1/production/sizes/{$sizes[0]['ulid']}")->assertUnprocessable();

        // From its page, an OF takes sizes picked one by one, without a grid: the planned quantity is their total.
        $this->client()->putJson("/api/v1/production/manufacturing-orders/{$of['ulid']}/sizes", ['size_grid' => null, 'sizes' => [['size' => 'XL', 'quantity' => 5], ['size' => 'M', 'quantity' => 10]]])->assertOk()
            ->assertJsonPath('data.size_grid', null)->assertJsonPath('data.sizes', [['size' => 'M', 'quantity' => 10], ['size' => 'XL', 'quantity' => 5]])->assertJsonPath('data.quantity_planned', 15);
        $this->client()->putJson("/api/v1/production/manufacturing-orders/{$of['ulid']}/sizes", ['size_grid' => null, 'sizes' => [['size' => '3XL', 'quantity' => 5]]])->assertUnprocessable();
        $this->client()->putJson("/api/v1/production/manufacturing-orders/{$of['ulid']}/sizes", ['size_grid' => $grid['ulid'], 'sizes' => [['size' => 'S', 'quantity' => 20], ['size' => 'M', 'quantity' => 40], ['size' => 'L', 'quantity' => 40], ['size' => 'XL', 'quantity' => 20]]])
            ->assertOk()->assertJsonPath('data.sizes.0', ['size' => 'XS', 'quantity' => 0])->assertJsonPath('data.quantity_planned', 120);

        // Removed by mistake: the OF goes back to the estimate of its article.
        $this->client()->deleteJson("/api/v1/production/spreads/{$ulid}")->assertOk()->assertJsonPath('data.cutting.totals.spreads', 0)->assertJsonPath('data.waste.estimated.source', 'article');
    }

    private function client(): static
    {
        return $this->withToken($this->tokenFor($this->admin, $this->company));
    }
}
