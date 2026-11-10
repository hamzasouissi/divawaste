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
 * (matelas) of its fabrics — placement length, efficiency, pieces of each size per layer, layers planned then cut —
 * which give the pieces, the fabric and the cutting waste expected, in all, by type of waste and shared by size.
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

    public function test_an_of_is_cut_spread_by_spread_with_its_sizes(): void
    {
        // A grid, reused by every OF that follows it.
        $grid = $this->client()->postJson('/api/v1/production/size-grids', ['name' => 'Adulte', 'sizes' => ['S', 'M', 'L', 'XL']])->assertCreated()->json('data');
        $this->client()->postJson('/api/v1/production/size-grids', ['name' => 'Adulte', 'sizes' => ['S']])->assertUnprocessable();
        $this->client()->getJson('/api/v1/production/size-grids')->assertOk()->assertJsonPath('data.0.sizes', ['S', 'M', 'L', 'XL']);

        $fabric = $this->client()->postJson('/api/v1/production/materials', ['reference' => 'F22-G1', 'name' => 'Coton', 'unit' => 'm', 'width_cm' => '150', 'grammage_gsm' => '200'])->assertCreated()->json('data');
        $label = $this->client()->postJson('/api/v1/production/materials', ['reference' => 'LABEL', 'name' => 'Etiquette', 'unit' => 'piece'])->assertCreated()->json('data');
        $article = $this->client()->postJson('/api/v1/production/articles', ['reference' => 'IKA', 'name' => 'Veste', 'materials' => [['material' => $fabric['ulid'], 'quantity_per_piece' => '1.5']]])
            ->assertCreated()->json('data');

        // The OF says its sizes: the pieces planned are their total; a size outside the grid is refused.
        $payload = ['order_number' => 'PO-18', 'article' => $article['ulid'], 'quantity_planned' => 1, 'size_grid' => $grid['ulid'],
            'sizes' => [['size' => 'S', 'quantity' => 20], ['size' => 'M', 'quantity' => 40], ['size' => 'L', 'quantity' => 40], ['size' => 'XL', 'quantity' => 20]]];
        $this->client()->postJson('/api/v1/production/manufacturing-orders', ['sizes' => [['size' => 'XXL', 'quantity' => 1]]] + $payload)->assertUnprocessable()->assertJsonStructure(['errors' => ['sizes']]);
        $of = $this->client()->postJson('/api/v1/production/manufacturing-orders', $payload)->assertCreated()
            ->assertJsonPath('data.quantity_planned', 120)->assertJsonPath('data.size_grid.name', 'Adulte')->assertJsonPath('data.sizes.1', ['size' => 'M', 'quantity' => 40])
            ->assertJsonPath('data.cutting', null)->assertJsonPath('data.waste.estimated.source', 'article')->json('data');

        // A spread: a 6.02 m placement at 82 %, 1 S, 2 M, 2 L, 1 XL per layer, 20 layers planned.
        $spread = ['material' => $fabric['ulid'], 'marker_length_m' => '6.02', 'efficiency_pct' => '82', 'layers_planned' => 20,
            'ratio' => [['size' => 'S', 'pieces' => 1], ['size' => 'M', 'pieces' => 2], ['size' => 'L', 'pieces' => 2], ['size' => 'XL', 'pieces' => 1]]];
        $this->client()->postJson("/api/v1/production/manufacturing-orders/{$of['ulid']}", [])->assertMethodNotAllowed();
        $this->client()->postJson("/api/v1/production/manufacturing-orders/{$of['ulid']}/spreads", ['material' => $label['ulid']] + $spread)->assertUnprocessable()->assertJsonStructure(['errors' => ['material']]);
        $this->client()->postJson("/api/v1/production/manufacturing-orders/{$of['ulid']}/spreads", ['ratio' => [['size' => 'XXL', 'pieces' => 1]]] + $spread)->assertUnprocessable();
        // 20 × 6.02 = 120.4 m × 1.5 m × 200 g = 36.12 kg of fabric; 18 % of it is 6.502 kg of cutting waste.
        $data = $this->client()->postJson("/api/v1/production/manufacturing-orders/{$of['ulid']}/spreads", $spread)->assertCreated()
            ->assertJsonPath('data.cutting.spreads.0.pieces_per_layer', 6)->assertJsonPath('data.cutting.spreads.0.planned.pieces', 120)
            ->assertJsonPath('data.cutting.spreads.0.planned.metres', '120.400')->assertJsonPath('data.cutting.spreads.0.planned.fabric_kg', '36.120')
            ->assertJsonPath('data.cutting.spreads.0.planned.waste_kg', '6.502')->assertJsonPath('data.cutting.spreads.0.done', null)
            ->assertJsonPath('data.cutting.spreads.0.waste_type.name', 'Chutes de coupe')
            ->assertJsonPath('data.cutting.by_size.1', ['size' => 'M', 'ordered' => 40, 'planned' => 40, 'cut' => null, 'waste_kg' => '2.167'])
            ->assertJsonPath('data.waste.estimated.source', 'cutting')->assertJsonPath('data.waste.estimated.planned_kg', '6.502')
            ->assertJsonPath('data.waste.estimated.produced_kg', null)->assertJsonPath('data.waste.estimated.by_type.0.planned_kg', '6.502')->json('data');

        // Not cut yet: nothing to record as a consumption.
        $ulid = $data['cutting']['spreads'][0]['ulid'];
        $this->client()->postJson("/api/v1/production/spreads/{$ulid}/consumption")->assertConflict()->assertJsonPath('code', 'SPREAD_NOT_CUT');

        // 19 layers really cut: 114.38 m, 34.314 kg, 6.177 kg of waste; each size takes its share by its pieces.
        $this->client()->putJson("/api/v1/production/spreads/{$ulid}", ['layers_done' => 19, 'cut_on' => '2026-11-10'] + $spread)->assertOk()
            ->assertJsonPath('data.cutting.spreads.0.done.pieces', 114)->assertJsonPath('data.cutting.spreads.0.done.metres', '114.380')
            ->assertJsonPath('data.cutting.spreads.0.done.waste_kg', '6.177')
            ->assertJsonPath('data.cutting.totals.done.pieces', 114)->assertJsonPath('data.cutting.totals.spreads_to_cut', 0)
            ->assertJsonPath('data.cutting.by_size.0', ['size' => 'S', 'ordered' => 20, 'planned' => 20, 'cut' => 19, 'waste_kg' => '1.030'])
            ->assertJsonPath('data.cutting.by_size.1.waste_kg', '2.059')
            ->assertJsonPath('data.cutting.fabrics.0.cut_m', '114.380')->assertJsonPath('data.cutting.fabrics.0.consumed_m', '0.000')
            ->assertJsonPath('data.waste.estimated.produced_kg', '6.177')->assertJsonPath('data.waste.estimated.by_type.0.produced_kg', '6.177');

        // The metres cut become the OF's consumption only when asked, and only once.
        $this->client()->postJson("/api/v1/production/spreads/{$ulid}/consumption")->assertOk()
            ->assertJsonPath('data.consumptions.0.quantity', '114.380')->assertJsonPath('data.cutting.spreads.0.consumption_recorded', true)
            ->assertJsonPath('data.cutting.fabrics.0.consumed_m', '114.380');
        $this->client()->postJson("/api/v1/production/spreads/{$ulid}/consumption")->assertConflict()->assertJsonPath('code', 'SPREAD_ALREADY_RECORDED');

        // An interlining cut for the same garments adds its fabric and its waste, not garments: 2.1 m at 88 %, 20 layers is
        // 42 m × 1.5 m × 40 g = 2.52 kg, 0.302 kg of waste. The pieces stay those of the main fabric.
        $interlining = $this->client()->postJson('/api/v1/production/materials', ['reference' => 'VESLINE', 'name' => 'Entoilage', 'unit' => 'm', 'width_cm' => '150', 'grammage_gsm' => '40'])
            ->assertCreated()->json('data');
        $added = $this->client()->postJson("/api/v1/production/manufacturing-orders/{$of['ulid']}/spreads", ['material' => $interlining['ulid'], 'marker_length_m' => '2.1', 'efficiency_pct' => '88'] + $spread)
            ->assertCreated()->assertJsonPath('data.cutting.main_material.reference', 'F22-G1')
            ->assertJsonPath('data.cutting.totals.planned.pieces', 120)->assertJsonPath('data.cutting.totals.planned.waste_kg', '6.804')
            ->assertJsonPath('data.cutting.by_size.1.planned', 40)->assertJsonPath('data.waste.estimated.planned_pieces', 120)->json('data');
        $this->client()->deleteJson("/api/v1/production/spreads/{$added['cutting']['spreads'][1]['ulid']}")->assertOk();

        // An allowance at the ends of each layer is fabric too: 2 cm per layer on 19 layers is 0.38 m more.
        $this->client()->putJson("/api/v1/production/spreads/{$ulid}", ['layers_done' => 19, 'end_allowance_cm' => '2'] + $spread)->assertOk()
            ->assertJsonPath('data.cutting.spreads.0.done.metres', '114.760');

        // A size an OF gave quantities to cannot leave the grid; another can be added.
        $this->client()->putJson("/api/v1/production/size-grids/{$grid['ulid']}", ['name' => 'Adulte', 'sizes' => ['S', 'M', 'L']])->assertUnprocessable();
        $this->client()->putJson("/api/v1/production/size-grids/{$grid['ulid']}", ['name' => 'Adulte', 'sizes' => ['XS', 'S', 'M', 'L', 'XL']])->assertOk()->assertJsonPath('data.orders', 1);

        // Removed by mistake: the OF goes back to the estimate of its article.
        $this->client()->deleteJson("/api/v1/production/spreads/{$ulid}")->assertOk()->assertJsonPath('data.cutting', null)->assertJsonPath('data.waste.estimated.source', 'article');
    }

    private function client(): static
    {
        return $this->withToken($this->tokenFor($this->admin, $this->company));
    }
}
