<?php

namespace Tests\Feature\Production;

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\Company;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Http\UploadedFile;
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
            ['material' => $rib['ulid'], 'quantity_per_piece' => '0.1', 'efficiency_pct' => '85'],
            ['material' => $label['ulid'], 'quantity_per_piece' => '1'],
            ['material' => $bias['ulid'], 'quantity_per_piece' => '0.4'],
        ]])->assertCreated()
            // 1.004 m × 1.60 m × 150 g = 240.96 g of knit; at the default 80 %, 48.19 g of it is waste.
            ->assertJsonPath('data.materials.0.efficiency_pct', null)->assertJsonPath('data.materials.0.efficiency_applied', '80.00')
            ->assertJsonPath('data.materials.0.fabric_kg_per_piece', '0.2410')->assertJsonPath('data.materials.0.waste_kg_per_piece', '0.0482')
            // 0.1 m × 1.50 m × 200 g = 30 g of rib, cut at its own 85 %: 4.5 g.
            ->assertJsonPath('data.materials.1.efficiency_pct', '85.00')->assertJsonPath('data.materials.1.waste_kg_per_piece', '0.0045')
            // A label, or a tape with no laize and grammage, is not cut from a placement: no figure rather than a wrong one.
            ->assertJsonPath('data.materials.2.waste_kg_per_piece', null)->assertJsonPath('data.materials.3.waste_kg_per_piece', null)
            ->assertJsonPath('data.cutting_waste.default_efficiency_pct', '80.00')
            ->assertJsonPath('data.cutting_waste.fabric_kg_per_piece', '0.2710')->assertJsonPath('data.cutting_waste.waste_kg_per_piece', '0.0527')
            ->json('data');

        $of = $this->client()->postJson('/api/v1/production/manufacturing-orders', ['order_number' => 'OF-1', 'article' => $article['ulid'], 'quantity_planned' => 1000, 'quantity_produced' => 960])
            ->assertCreated()->json('data');
        $this->client()->getJson("/api/v1/production/manufacturing-orders/{$of['ulid']}")->assertOk()
            ->assertJsonPath('data.waste.estimated.per_piece_kg', '0.0527')
            ->assertJsonPath('data.waste.estimated.planned_kg', '52.692')->assertJsonPath('data.waste.estimated.produced_kg', '50.584');

        // The company says its own usual efficiency: every line without one follows it.
        $this->client()->putJson('/api/v1/company', ['cutting_efficiency_pct' => '82'])->assertOk()->assertJsonPath('data.cutting_efficiency_pct', '82');
        $this->client()->getJson('/api/v1/production/articles')->assertOk()
            ->assertJsonPath('data.0.materials.0.efficiency_applied', '82')->assertJsonPath('data.0.materials.0.waste_kg_per_piece', '0.0434')
            ->assertJsonPath('data.0.materials.1.efficiency_applied', '85.00');
        $this->client()->putJson('/api/v1/company', ['cutting_efficiency_pct' => '120'])->assertUnprocessable();

        // An article without any fabric it can be computed for has no expected waste, and neither has its OF.
        $plain = $this->client()->postJson('/api/v1/production/articles', ['reference' => 'BADGE', 'name' => 'Badge', 'materials' => [['material' => $label['ulid'], 'quantity_per_piece' => '1']]])
            ->assertCreated()->assertJsonPath('data.cutting_waste.waste_kg_per_piece', null)->json('data');
        $other = $this->client()->postJson('/api/v1/production/manufacturing-orders', ['order_number' => 'OF-2', 'article' => $plain['ulid'], 'quantity_planned' => 10])->assertCreated()->json('data');
        $this->client()->getJson("/api/v1/production/manufacturing-orders/{$other['ulid']}")->assertOk()->assertJsonPath('data.waste.estimated', null);

        $this->client()->postJson('/api/v1/production/articles', ['reference' => 'X', 'name' => 'X', 'materials' => [['material' => $knit['ulid'], 'quantity_per_piece' => '1', 'efficiency_pct' => '0']]])
            ->assertUnprocessable()->assertJsonStructure(['errors' => ['materials.0.efficiency_pct']]);
    }

    public function test_the_efficiency_of_a_placement_comes_in_with_the_composition_file(): void
    {
        $this->material(['reference' => 'MS080-782-160', 'unit' => 'm', 'width_cm' => '160', 'grammage_gsm' => '150']);
        $csv = implode("\n", ['RefArticle;RefMP;Qtte;Efficience', 'TSHIRT;MS080-782-160;1.004;75', 'POLO;MS080-782-160;1.2;150']);
        $file = fn () => UploadedFile::fake()->createWithContent('compo.csv', $csv);

        $preview = $this->client()->post('/api/v1/production/imports/preview', ['target' => 'articles', 'file' => $file()], ['Accept' => 'application/json'])->assertOk()->json('data');
        $this->assertSame(3, $preview['mapping']['efficiency_pct']);
        $mapping = ['reference' => 0, 'material' => 1, 'quantity_per_piece' => 2, 'efficiency_pct' => 3];

        $this->client()->post('/api/v1/production/imports/run', ['target' => 'articles', 'file' => $file(), 'mapping' => $mapping], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('data.created', 1)->assertJsonCount(1, 'data.errors');
        // 240.96 g of knit at 75 %: 60.24 g of waste.
        $this->client()->getJson('/api/v1/production/articles?search=TSHIRT')->assertOk()
            ->assertJsonPath('data.0.materials.0.efficiency_pct', '75.00')->assertJsonPath('data.0.materials.0.waste_kg_per_piece', '0.0602');
    }

    /**
     * @param  array<string, string>  $values
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
