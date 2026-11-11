<?php

namespace Tests\Feature\Production;

use App\Modules\Catalog\Models\WasteType;
use App\Modules\Identity\Models\User;
use App\Modules\Sites\Models\Zone;
use App\Modules\Tenancy\Models\Company;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * The cutting waste expected before anything is cut: for a fabric of an article, its consumption per piece (gross,
 * from the placement) × laize × grammage gives the fabric used, and (100 − efficiency) % of it is the waste.
 */
class CuttingWasteTest extends TestCase
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
    }

    public function test_an_article_and_its_of_say_the_cutting_waste_expected(): void
    {
        // A knit 160 cm wide at 150 g/m², a rib 150 cm at 200 g/m², and a label (no placement).
        $knit = $this->material(['reference' => 'MS080-782-160', 'unit' => 'm', 'width_cm' => '160', 'grammage_gsm' => '150']);
        $rib = $this->material(['reference' => 'RIB-150', 'unit' => 'm', 'width_cm' => '150', 'grammage_gsm' => '200']);
        $label = $this->material(['reference' => 'LABEL', 'unit' => 'piece']);
        $bias = $this->material(['reference' => 'BIAS', 'unit' => 'm']);

        $article = $this->client()->postJson('/api/v1/production/articles', ['reference' => 'TSHIRT', 'name' => 'T-shirt', 'materials' => [
            ['material' => $knit['ulid'], 'quantity_per_piece' => '1.004'],
            ['material' => $rib['ulid'], 'quantity_per_piece' => '0.1'],
            ['material' => $label['ulid'], 'quantity_per_piece' => '1'],
            ['material' => $bias['ulid'], 'quantity_per_piece' => '0.4'],
        ]])->assertCreated()
            // 1.004 m × 1.60 m × 150 g = 240.96 g of knit; at the default 80 %, 48.19 g of it is waste.
            ->assertJsonMissingPath('data.materials.0.efficiency_pct')->assertJsonPath('data.materials.0.efficiency_applied', '80.00')
            ->assertJsonPath('data.materials.0.fabric_kg_per_piece', '0.2410')->assertJsonPath('data.materials.0.waste_kg_per_piece', '0.0482')
            // 0.1 m × 1.50 m × 200 g = 30 g of rib, at the same usual 80 %: 6 g.
            ->assertJsonPath('data.materials.1.waste_kg_per_piece', '0.0060')
            // A label, or a tape with no laize and grammage, is not cut from a placement: no figure rather than a wrong one.
            ->assertJsonPath('data.materials.2.waste_kg_per_piece', null)->assertJsonPath('data.materials.3.waste_kg_per_piece', null)
            ->assertJsonPath('data.cutting_waste.default_efficiency_pct', '80.00')
            ->assertJsonPath('data.cutting_waste.fabric_kg_per_piece', '0.2710')->assertJsonPath('data.cutting_waste.waste_kg_per_piece', '0.0542')
            ->json('data');

        $of = $this->client()->postJson('/api/v1/production/manufacturing-orders', ['order_number' => 'OF-1', 'article' => $article['ulid'], 'quantity_planned' => 1000, 'quantity_produced' => 960])
            ->assertCreated()->json('data');
        $this->client()->getJson("/api/v1/production/manufacturing-orders/{$of['ulid']}")->assertOk()
            ->assertJsonPath('data.waste.estimated.per_piece_kg', '0.0542')
            ->assertJsonPath('data.waste.estimated.planned_kg', '54.192')->assertJsonPath('data.waste.estimated.produced_kg', '52.024');

        // The company says its own usual efficiency: every fabric of every article follows it.
        $this->client()->putJson('/api/v1/company', ['cutting_efficiency_pct' => '82'])->assertOk()->assertJsonPath('data.cutting_efficiency_pct', '82');
        $this->client()->getJson('/api/v1/production/articles')->assertOk()
            ->assertJsonPath('data.0.materials.0.efficiency_applied', '82')->assertJsonPath('data.0.materials.0.waste_kg_per_piece', '0.0434')
            ->assertJsonPath('data.0.materials.1.efficiency_applied', '82')->assertJsonPath('data.0.materials.1.waste_kg_per_piece', '0.0054');
        $this->client()->putJson('/api/v1/company', ['cutting_efficiency_pct' => '120'])->assertUnprocessable();

        // An article without any fabric it can be computed for has no expected waste, and neither has its OF.
        $plain = $this->client()->postJson('/api/v1/production/articles', ['reference' => 'BADGE', 'name' => 'Badge', 'materials' => [['material' => $label['ulid'], 'quantity_per_piece' => '1']]])
            ->assertCreated()->assertJsonPath('data.cutting_waste.waste_kg_per_piece', null)->json('data');
        $other = $this->client()->postJson('/api/v1/production/manufacturing-orders', ['order_number' => 'OF-2', 'article' => $plain['ulid'], 'quantity_planned' => 10])->assertCreated()->json('data');
        $this->client()->getJson("/api/v1/production/manufacturing-orders/{$other['ulid']}")->assertOk()->assertJsonPath('data.waste.estimated', null);

        // An article no longer says an efficiency of its own: one sent is not kept.
        $this->client()->postJson('/api/v1/production/articles', ['reference' => 'X', 'name' => 'X', 'materials' => [['material' => $knit['ulid'], 'quantity_per_piece' => '1', 'efficiency_pct' => '50']]])
            ->assertCreated()->assertJsonMissingPath('data.materials.0.efficiency_pct')->assertJsonPath('data.materials.0.efficiency_applied', '82');
    }

    public function test_a_composition_file_brings_no_efficiency(): void
    {
        $this->material(['reference' => 'MS080-782-160', 'unit' => 'm', 'width_cm' => '160', 'grammage_gsm' => '150']);
        $csv = implode("\n", ['RefArticle;RefMP;Qtte;Efficience', 'TSHIRT;MS080-782-160;1.004;75', 'POLO;MS080-782-160;1.2;150']);
        $file = fn () => UploadedFile::fake()->createWithContent('compo.csv', $csv);

        // The efficiency belongs to the spreads of an OF: an "Efficience" column of a composition is not read.
        $preview = $this->client()->post('/api/v1/production/imports/preview', ['target' => 'articles', 'file' => $file()], ['Accept' => 'application/json'])->assertOk()->json('data');
        $this->assertArrayNotHasKey('efficiency_pct', $preview['mapping']);
        $mapping = ['reference' => 0, 'material' => 1, 'quantity_per_piece' => 2];

        $this->client()->post('/api/v1/production/imports/run', ['target' => 'articles', 'file' => $file(), 'mapping' => $mapping], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('data.created', 2)->assertJsonCount(0, 'data.errors');
        // 240.96 g of knit at the usual 80 %: 48.19 g of waste.
        $this->client()->getJson('/api/v1/production/articles?search=TSHIRT')->assertOk()->assertJsonPath('data.0.materials.0.waste_kg_per_piece', '0.0482');
    }

    public function test_each_fabric_says_the_waste_it_leaves_and_the_of_compares_like_with_like(): void
    {
        $site = $this->makeSite($this->company, 'NAB');
        [$scraps, $selvedges, $films, $zone] = $this->tenant()->runAs($this->company, fn () => [
            $this->type('CUTTING_SCRAPS', 'Chutes de coupe', 'cutting_scraps'), $this->type('SELVEDGES', 'Lisières', 'selvedges'), $this->type('FILMS', 'Films', 'plastic_films_bags'),
            Zone::query()->create(['site_id' => $site->id, 'zone_type_id' => DB::table('zone_types')->value('id'), 'code' => 'COUPE', 'name' => 'Coupe']),
        ]);
        // A cotton that says nothing leaves the company's cutting scraps; an interlining says its own waste.
        $cotton = $this->material(['reference' => 'F22-G1', 'unit' => 'm', 'width_cm' => '150', 'grammage_gsm' => '200', 'composition' => [['material' => 'CO', 'pct' => '100']]]);
        $this->assertSame(['waste_type' => ['ulid' => $scraps->ulid, 'name' => 'Chutes de coupe'], 'method' => 'cutting', 'share_pct' => '100.00', 'rate_pct' => null, 'kg_per_unit' => null],
            $cotton['waste_types'][0]);
        $this->assertSame('fabric', $cotton['kind']);
        $this->assertTrue($cotton['waste_types_inherited']);
        $interlining = $this->material(['reference' => 'VESLINE', 'unit' => 'm', 'width_cm' => '150', 'grammage_gsm' => '40', 'waste_type' => $selvedges->ulid]);
        $this->assertSame('Lisières', $interlining['waste_types'][0]['waste_type']['name']);
        $this->assertFalse($interlining['waste_types_inherited']);
        $label = $this->material(['reference' => 'LABEL', 'unit' => 'piece']);
        $this->assertSame([], $label['waste_types']);

        $article = $this->client()->postJson('/api/v1/production/articles', ['reference' => 'IKA', 'name' => 'Veste', 'materials' => [
            ['material' => $cotton['ulid'], 'quantity_per_piece' => '1.5'], ['material' => $interlining['ulid'], 'quantity_per_piece' => '0.5'], ['material' => $label['ulid'], 'quantity_per_piece' => '1'],
        ]])->assertCreated()->json('data');
        // 1.5 × 1.5 × 200 g × 20 % = 90 g of cotton scraps; 0.5 × 1.5 × 40 g × 20 % = 6 g of selvedges.
        $this->client()->getJson("/api/v1/production/articles/{$article['ulid']}")->assertOk()
            ->assertJsonPath('data.materials.0.waste_types.0.waste_type.name', 'Chutes de coupe')->assertJsonPath('data.materials.0.waste_type_inherited', true)
            ->assertJsonPath('data.materials.0.composition.0.material', 'CO')->assertJsonPath('data.materials.0.width_cm', '150.00')
            ->assertJsonPath('data.materials.1.waste_types.0.waste_type.name', 'Lisières')->assertJsonPath('data.materials.1.waste_types.0.waste_kg_per_piece', '0.006000')
            ->assertJsonPath('data.materials.2.waste_types', [])
            ->assertJsonPath('data.cutting_waste.waste_kg_per_piece', '0.0960')
            ->assertJsonPath('data.cutting_waste.by_type.0.waste_type.name', 'Chutes de coupe')->assertJsonPath('data.cutting_waste.by_type.0.waste_kg_per_piece', '0.0900')
            ->assertJsonPath('data.cutting_waste.by_type.1.waste_type.name', 'Lisières')->assertJsonPath('data.cutting_waste.by_type.1.waste_kg_per_piece', '0.0060');

        $of = $this->client()->postJson('/api/v1/production/manufacturing-orders', ['order_number' => 'PO-18', 'article' => $article['ulid'], 'quantity_planned' => 110, 'quantity_produced' => 100,
            'consumptions' => [['material' => $cotton['ulid'], 'quantity' => '174']]])->assertCreated()->json('data');
        $lot = fn (WasteType $type, ?string $kg) => $this->client()->postJson('/api/v1/lots', ['site' => $site->ulid, 'zone' => $zone->ulid, 'waste_type' => $type->ulid,
            'production_order' => $of['ulid'], 'raw_material' => $cotton['ulid']] + ($kg === null ? [] : ['net_weight_kg' => $kg]))->assertCreated();
        $lot($scraps, null);
        $waste = $this->client()->getJson("/api/v1/production/manufacturing-orders/{$of['ulid']}")->assertOk()
            // Not weighed yet: no rate rather than 0 %.
            ->assertJsonPath('data.waste.waste_rate_pct', null)
            ->assertJsonPath('data.waste.estimated.by_type.0.waste_type.name', 'Chutes de coupe')->assertJsonPath('data.waste.estimated.by_type.0.planned_kg', '9.900')
            ->assertJsonPath('data.waste.estimated.by_type.0.produced_kg', '9.000')->assertJsonPath('data.waste.estimated.by_type.0.unweighed_lots', 1)
            ->assertJsonPath('data.waste.estimated.by_type.1.produced_kg', '0.600')->assertJsonPath('data.waste.estimated.by_type.1.weighed_kg', null)->json('data.waste');
        $this->assertSame('0.000', $waste['estimated']['by_type'][0]['weighed_kg']);

        // Packaging weighed on the OF is not set against the cutting scraps.
        $lot($scraps, '8.5');
        $lot($films, '3');
        $this->client()->getJson("/api/v1/production/manufacturing-orders/{$of['ulid']}")->assertOk()
            ->assertJsonPath('data.waste.estimated.by_type.0.weighed_kg', '8.500')->assertJsonPath('data.waste.estimated.by_type.0.lots', 2)
            ->assertJsonPath('data.waste.kg', '11.500');

        // The waste type comes in with the materials file, by its name or its code; an unknown one is refused on its line.
        $csv = implode("\n", ['Reference;Unite;Type de dechet', 'F22-G1;m;SELVEDGES', 'NEW-1;m;Inconnu']);
        $this->client()->post('/api/v1/production/imports/run', ['target' => 'materials', 'file' => UploadedFile::fake()->createWithContent('mp.csv', $csv),
            'mapping' => ['reference' => 0, 'unit' => 1, 'waste_type' => 2]], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.updated', 1)->assertJsonCount(1, 'data.errors');
        $this->client()->getJson("/api/v1/production/materials/{$cotton['ulid']}")->assertOk()->assertJsonPath('data.waste_types.0.waste_type.name', 'Lisières');
        // A form that does not send the waste type leaves it as it is; sent empty, the fabric is back to the cutting scraps.
        $base = ['reference' => 'F22-G1', 'name' => 'F22-G1', 'unit' => 'm', 'width_cm' => '150', 'grammage_gsm' => '200'];
        $this->client()->putJson("/api/v1/production/materials/{$cotton['ulid']}", $base)->assertOk()->assertJsonPath('data.waste_types.0.waste_type.name', 'Lisières');
        $this->client()->putJson("/api/v1/production/materials/{$cotton['ulid']}", $base + ['waste_types' => []])->assertOk()
            ->assertJsonPath('data.waste_types.0.waste_type.name', 'Chutes de coupe')->assertJsonPath('data.waste_types_inherited', true);

        // A fabric can leave several types, each with its share; the shares make 100 %.
        $this->client()->putJson("/api/v1/production/materials/{$cotton['ulid']}", $base + ['waste_types' => [['waste_type' => $scraps->ulid, 'share_pct' => '85'], ['waste_type' => $selvedges->ulid, 'share_pct' => '10']]])
            ->assertUnprocessable()->assertJsonStructure(['errors' => ['waste_types']]);
        $this->client()->putJson("/api/v1/production/materials/{$cotton['ulid']}", $base + ['waste_types' => [['waste_type' => $scraps->ulid, 'share_pct' => '85'], ['waste_type' => $selvedges->ulid, 'share_pct' => '15']]])
            ->assertOk()->assertJsonCount(2, 'data.waste_types')->assertJsonPath('data.waste_types.1.share_pct', '15.00')->assertJsonPath('data.waste_types_inherited', false);
        // 90 g of cotton waste: 76.5 g of scraps and 13.5 g of selvedges, beside the interlining's 6 g of selvedges.
        $this->client()->getJson("/api/v1/production/articles/{$article['ulid']}")->assertOk()
            ->assertJsonPath('data.materials.0.waste_types.0.waste_kg_per_piece', '0.076500')->assertJsonPath('data.materials.0.waste_types.1.waste_kg_per_piece', '0.013500')
            ->assertJsonPath('data.cutting_waste.waste_kg_per_piece', '0.0960')
            ->assertJsonPath('data.cutting_waste.by_type.0.waste_type.name', 'Chutes de coupe')->assertJsonPath('data.cutting_waste.by_type.0.waste_kg_per_piece', '0.0765')
            ->assertJsonPath('data.cutting_waste.by_type.1.waste_type.name', 'Lisières')->assertJsonPath('data.cutting_waste.by_type.1.waste_kg_per_piece', '0.0195');
        // The OF sets each share beside the lots of its type: 100 pieces, 7.65 kg of scraps expected.
        $this->client()->getJson("/api/v1/production/manufacturing-orders/{$of['ulid']}")->assertOk()
            ->assertJsonPath('data.waste.estimated.by_type.0.produced_kg', '7.650')->assertJsonPath('data.waste.estimated.by_type.1.produced_kg', '1.950');
    }

    public function test_each_material_leaves_its_waste_by_its_own_calculation(): void
    {
        [$scraps, $yarn, $cardboard] = $this->tenant()->runAs($this->company, fn () => [
            $this->type('CUTTING_SCRAPS', 'Chutes de coupe', 'cutting_scraps'), $this->type('FILS', 'Déchets de fil', 'yarn_waste'), $this->type('CARTON', 'Cartons', 'cardboard'),
        ]);
        // A fabric, a thread counted in kilograms, its cones counted in pieces, cartons of 450 g, and a button.
        $fabric = $this->material(['reference' => 'F1', 'unit' => 'm', 'width_cm' => '150', 'grammage_gsm' => '200']);
        $this->assertSame('fabric', $fabric['kind']);
        $thread = $this->material(['reference' => 'FIL-1', 'unit' => 'kg', 'kind' => 'thread', 'waste_types' => [['waste_type' => $yarn->ulid, 'method' => 'loss_rate', 'rate_pct' => '5']]]);
        $this->assertSame('loss_rate', $thread['waste_types'][0]['method']);
        // Each calculation needs its figure; a share of the cutting waste is a fabric's only.
        $this->client()->postJson('/api/v1/production/materials', ['reference' => 'X', 'name' => 'X', 'unit' => 'piece', 'waste_types' => [['waste_type' => $yarn->ulid, 'method' => 'per_unit']]])
            ->assertUnprocessable()->assertJsonStructure(['errors' => ['waste_types.0.kg_per_unit']]);
        $this->client()->postJson('/api/v1/production/materials', ['reference' => 'X', 'name' => 'X', 'unit' => 'piece', 'waste_types' => [['waste_type' => $scraps->ulid, 'method' => 'cutting', 'share_pct' => '100']]])
            ->assertUnprocessable()->assertJsonStructure(['errors' => ['waste_types.0.method']]);
        $cones = $this->material(['reference' => 'CONE', 'unit' => 'piece', 'kind' => 'thread', 'waste_types' => [['waste_type' => $yarn->ulid, 'method' => 'per_unit', 'kg_per_unit' => '0.025']]]);
        $carton = $this->material(['reference' => 'CARTON-40', 'unit' => 'piece', 'kind' => 'packaging', 'unit_weight_kg' => '0.45', 'waste_types' => [['waste_type' => $cardboard->ulid, 'method' => 'whole']]]);
        $this->assertSame('0.450000', $carton['unit_weight_kg']);
        $button = $this->material(['reference' => 'BTN', 'unit' => 'piece', 'kind' => 'trim']);

        $article = $this->client()->postJson('/api/v1/production/articles', ['reference' => 'VESTE', 'name' => 'Veste', 'materials' => [
            ['material' => $fabric['ulid'], 'quantity_per_piece' => '1.5'], ['material' => $thread['ulid'], 'quantity_per_piece' => '0.01'],
            ['material' => $cones['ulid'], 'quantity_per_piece' => '0.002'], ['material' => $carton['ulid'], 'quantity_per_piece' => '0.02'],
            ['material' => $button['ulid'], 'quantity_per_piece' => '4'],
        ]])->assertCreated()->json('data');
        // Per piece: 1.5 m × 1.5 m × 200 g = 450 g of fabric, 20 % of it cut away: 90 g of scraps; 5 % of 10 g of thread: 0.5 g;
        // an empty cone of 25 g for 0.002 cone: 0.05 g; a fiftieth of a 450 g carton: 9 g; a button: nothing.
        $this->client()->getJson("/api/v1/production/articles/{$article['ulid']}")->assertOk()
            ->assertJsonPath('data.materials.0.waste_types.0.waste_kg_per_piece', '0.090000')
            ->assertJsonPath('data.materials.1.consumed_kg_per_piece', '0.010000')->assertJsonPath('data.materials.1.waste_types.0.waste_kg_per_piece', '0.000500')
            ->assertJsonPath('data.materials.2.waste_types.0.waste_kg_per_piece', '0.000050')
            ->assertJsonPath('data.materials.3.consumed_kg_per_piece', '0.009000')->assertJsonPath('data.materials.3.waste_types.0.waste_kg_per_piece', '0.009000')
            ->assertJsonPath('data.materials.4.waste_types', [])->assertJsonPath('data.materials.4.waste_kg_per_piece', null)
            ->assertJsonPath('data.cutting_waste.cutting_kg_per_piece', '0.0900')->assertJsonPath('data.cutting_waste.other_kg_per_piece', '0.0096')
            ->assertJsonPath('data.cutting_waste.waste_kg_per_piece', '0.0996')
            ->assertJsonPath('data.cutting_waste.by_type.1.waste_type.name', 'Cartons')->assertJsonPath('data.cutting_waste.by_type.2.waste_kg_per_piece', '0.0006');

        // An OF of 100 pieces, 80 produced: every type from the article.
        $of = $this->client()->postJson('/api/v1/production/manufacturing-orders', ['order_number' => 'OF-1', 'article' => $article['ulid'], 'quantity_planned' => 100, 'quantity_produced' => 80])
            ->assertCreated()->assertJsonPath('data.waste.estimated.source', 'article')
            ->assertJsonPath('data.waste.estimated.by_type.0.planned_kg', '9.000')->assertJsonPath('data.waste.estimated.by_type.0.produced_kg', '7.200')
            ->assertJsonPath('data.waste.estimated.by_type.1.planned_kg', '0.900')->assertJsonPath('data.waste.estimated.by_type.2.produced_kg', '0.044')->json('data');
        // Spread, the fabric's cutting comes from its layers (10 × 16 m = 160 m, 48 kg, 9.6 kg at 80 %); threads and cartons stay the article's.
        $this->client()->postJson("/api/v1/production/manufacturing-orders/{$of['ulid']}/spreads", ['material' => $fabric['ulid'], 'length_m' => '16', 'layers_planned' => 10, 'efficiency_pct' => '80'])
            ->assertCreated()->assertJsonPath('data.waste.estimated.source', 'cutting')
            ->assertJsonPath('data.waste.estimated.by_type.0.waste_type.name', 'Chutes de coupe')->assertJsonPath('data.waste.estimated.by_type.0.planned_kg', '9.600')
            ->assertJsonPath('data.waste.estimated.by_type.0.produced_kg', null)->assertJsonPath('data.waste.estimated.by_type.1.planned_kg', '0.900')
            ->assertJsonPath('data.waste.estimated.planned_kg', '10.555');

        // The nature and the unit weight come in with the materials file.
        $csv = implode("\n", ['Reference;Unite;Nature de la matiere;Poids unitaire', 'SAC-1;piece;Emballage;0,02', 'BAD;piece;Bois;1']);
        $this->client()->post('/api/v1/production/imports/run', ['target' => 'materials', 'file' => UploadedFile::fake()->createWithContent('mp.csv', $csv),
            'mapping' => ['reference' => 0, 'unit' => 1, 'nature' => 2, 'unit_weight_kg' => 3]], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('data.created', 1)->assertJsonCount(1, 'data.errors');
        $this->client()->getJson('/api/v1/production/materials?search=SAC-1')->assertOk()->assertJsonPath('data.0.kind', 'packaging')->assertJsonPath('data.0.unit_weight_kg', '0.020000');
    }

    private function type(string $code, string $name, string $catalog): WasteType
    {
        return WasteType::query()->create(['code' => $code, 'name' => $name, 'waste_family_id' => DB::table('waste_families')->value('id'),
            'waste_catalog_item_id' => DB::table('waste_catalog_items')->where('code', $catalog)->value('id'),
            'default_unit_id' => DB::table('units')->where('code', 'kg')->value('id'), 'activated_at' => now()]);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function material(array $values): array
    {
        return $this->client()->postJson('/api/v1/production/materials', $values + ['name' => $values['reference']])->assertCreated()->json('data');
    }

    private function client(): static
    {
        return $this->withToken($this->tokenFor($this->admin, $this->company));
    }
}
