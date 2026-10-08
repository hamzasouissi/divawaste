<?php

namespace Tests\Feature\Production;

use App\Modules\Identity\Models\User;
use App\Modules\Sites\Models\Site;
use App\Modules\Tenancy\Models\Company;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Production reference: clients, suppliers, materials and their deliveries, articles and what they consume, orders,
 * manufacturing orders with their stages on several sites — typed in, or imported from any spreadsheet.
 */
class ProductionReferenceTest extends TestCase
{
    use InteractsWithTenants;

    private Company $company;

    private Site $cutting;

    private Site $sewing;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([ReferenceDataSeeder::class, AuthorizationSeeder::class]);
        $this->company = $this->makeCompany();
        $this->cutting = $this->makeSite($this->company, 'NAB');
        $this->sewing = $this->makeSite($this->company, 'KOR');
        $this->admin = $this->makeMember($this->company, ['client_admin' => null]);
    }

    public function test_the_chain_from_client_to_manufacturing_order_is_typed_in(): void
    {
        $client = $this->client()->postJson('/api/v1/production/clients', ['code' => 'LEV', 'name' => 'Levant Denim', 'country_code' => 'FR'])->assertCreated()->json('data');
        $this->client()->postJson('/api/v1/production/clients', ['code' => 'LEV', 'name' => 'Doublon'])->assertUnprocessable();
        $supplier = $this->client()->postJson('/api/v1/production/suppliers', ['code' => 'TIS', 'name' => 'Tissages du Nord'])->assertCreated()->json('data');

        // A material: counted in metres, with its composition and the deliveries received.
        $material = $this->client()->postJson('/api/v1/production/materials', ['reference' => 'DEN-12OZ', 'name' => 'Denim 12 oz', 'supplier' => $supplier['ulid'], 'unit' => 'm',
            'color_label' => '19-4052 TCX', 'composition' => [['material' => 'CO', 'pct' => '98'], ['material' => 'EL', 'pct' => '2']]])
            ->assertCreated()->assertJsonPath('data.supplier.name', 'Tissages du Nord')->assertJsonPath('data.unit', 'm')->json('data');
        $this->client()->postJson('/api/v1/production/materials', ['reference' => 'X', 'name' => 'X', 'unit' => 'm', 'composition' => [['material' => 'CO', 'pct' => '90']]])
            ->assertUnprocessable()->assertJsonStructure(['errors' => ['composition']]);
        $batch = $this->client()->postJson("/api/v1/production/materials/{$material['ulid']}/batches", ['batch_number' => 'R-0412', 'received_on' => '2026-09-01', 'quantity' => '1200'])
            ->assertCreated()->json('data.batches.0');

        // An article says what one piece consumes; an order lists articles; an OF makes one of them.
        $article = $this->client()->postJson('/api/v1/production/articles', ['reference' => 'JEAN-501', 'name' => 'Jean droit', 'client' => $client['ulid'],
            'materials' => [['material' => $material['ulid'], 'quantity_per_piece' => '1.35']]])->assertCreated()->assertJsonPath('data.materials.0.unit', 'm')->json('data');
        $other = $this->client()->postJson('/api/v1/production/articles', ['reference' => 'VESTE-1', 'name' => 'Veste'])->assertCreated()->json('data');
        $order = $this->client()->postJson('/api/v1/production/orders', ['order_number' => 'CMD-88', 'client' => $client['ulid'], 'ordered_on' => '2026-09-10',
            'lines' => [['article' => $article['ulid'], 'quantity' => 1000]]])->assertCreated()->assertJsonPath('data.quantity', 1000)->json('data');

        $payload = ['order_number' => 'OF-2026-014', 'article' => $article['ulid'], 'customer_order' => $order['ulid'], 'quantity_planned' => 1000, 'quantity_produced' => 960,
            'started_on' => '2026-09-15', 'status' => 'in_progress',
            // Cut on one site, sewn on another.
            'steps' => [['step' => 'cutting', 'site' => $this->cutting->ulid, 'pieces_done' => 1000], ['step' => 'sewing', 'site' => $this->sewing->ulid, 'pieces_done' => 960]],
            'consumptions' => [['material' => $material['ulid'], 'batch' => $batch['ulid'], 'quantity' => '1420.5']]];
        $this->client()->postJson('/api/v1/production/manufacturing-orders', ['article' => $other['ulid']] + $payload)->assertConflict()->assertJsonPath('code', 'ARTICLE_NOT_IN_ORDER');
        $of = $this->client()->postJson('/api/v1/production/manufacturing-orders', $payload)->assertCreated()
            ->assertJsonPath('data.client.name', 'Levant Denim')->assertJsonPath('data.steps.1.site_name', $this->sewing->name)
            ->assertJsonPath('data.consumptions.0.batch_number', 'R-0412')
            // Expected 1000 × 1.35 m, consumed 1420.5 m: the gap is where waste hides.
            ->assertJsonPath('data.planned_consumption.0.planned', '1350.000')->assertJsonPath('data.planned_consumption.0.consumed', '1420.500')->json('data');

        $this->client()->getJson('/api/v1/production/manufacturing-orders?site='.$this->sewing->ulid)->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.ulid', $of['ulid']);
        $this->client()->getJson('/api/v1/production/orders')->assertOk()->assertJsonPath('data.0.manufacturing_orders', 1);

        // Read-only for the direction, nothing for an operator, and nothing at all for another company.
        $viewer = $this->tokenFor($this->makeMember($this->company, ['management_viewer' => null]), $this->company);
        $this->withToken($viewer)->getJson('/api/v1/production/clients')->assertOk()->assertJsonPath('meta.total', 1);
        $this->withToken($viewer)->postJson('/api/v1/production/clients', ['code' => 'Z', 'name' => 'Z'])->assertForbidden();
        $operator = $this->tokenFor($this->makeMember($this->company, ['workshop_operator' => $this->cutting]), $this->company);
        $this->withToken($operator)->getJson('/api/v1/production/clients')->assertForbidden();
        $elsewhere = $this->makeCompany();
        $stranger = $this->tokenFor($this->makeMember($elsewhere, ['client_admin' => null]), $elsewhere);
        $this->withToken($stranger)->getJson('/api/v1/production/manufacturing-orders')->assertOk()->assertJsonPath('meta.total', 0);
        $this->withToken($stranger)->getJson("/api/v1/production/manufacturing-orders/{$of['ulid']}")->assertNotFound();
        $this->withToken($stranger)->postJson('/api/v1/production/articles', ['reference' => 'A', 'name' => 'A', 'client' => $client['ulid']])->assertUnprocessable();
    }

    public function test_stages_are_the_defaults_plus_the_ones_the_company_adds(): void
    {
        $defaults = DB::table('production_steps')->whereNull('company_id')->count();
        $this->assertGreaterThanOrEqual(7, $defaults);

        $custom = $this->client()->postJson('/api/v1/production/steps', ['name' => 'Broderie'])->assertCreated()->assertJsonPath('data.is_custom', true)->json('data.code');
        $this->client()->getJson('/api/v1/production/steps')->assertOk()->assertJsonCount($defaults + 1, 'data');
        $this->client()->putJson('/api/v1/production/steps/cutting', ['name' => 'Autre'])->assertNotFound();

        $elsewhere = $this->makeCompany();
        $stranger = $this->tokenFor($this->makeMember($elsewhere, ['client_admin' => null]), $elsewhere);
        $this->withToken($stranger)->getJson('/api/v1/production/steps')->assertOk()->assertJsonCount($defaults, 'data');
        $this->withToken($stranger)->putJson("/api/v1/production/steps/{$custom}", ['name' => 'Vol'])->assertNotFound();
    }

    public function test_a_flat_export_is_imported_by_saying_where_each_information_is(): void
    {
        // An ERP export with its own headings, in its own order, dates and numbers written the French way.
        $csv = implode("\n", [
            'Num OF;Modele;Donneur d\'ordre;Commande;Qte lancee;Date lancement;Atelier;Usine;Colonne inutile',
            'OF-100;JEAN-501;Levant Denim;CMD-88;1 000;15/09/2026;Coupe;NAB;x',
            'OF-100;JEAN-501;Levant Denim;CMD-88;1 000;15/09/2026;Confection;KOR;x',
            'OF-101;VESTE-1;Levant Denim;CMD-88;abc;16/09/2026;Coupe;NAB;x',
            'OF-102;VESTE-1;Levant Denim;CMD-89;250;32/13/2026;Coupe;NAB;x',
            'OF-103;VESTE-1;Levant Denim;CMD-89;250;17/09/2026;Tricotage;NAB;x',
        ]);
        $file = fn () => UploadedFile::fake()->createWithContent('export-of.csv', str_replace(';', ',', $csv));

        // Step 1: the headings are read and matched to the information they seem to hold.
        $preview = $this->client()->post('/api/v1/production/imports/preview', ['target' => 'manufacturing_orders', 'file' => $file()], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.lines', 5)->assertJsonPath('data.headers.0', 'Num OF')->json('data');
        $this->assertEquals(['order_number' => 0, 'article' => 1, 'client' => 2, 'customer_order' => 3, 'quantity_planned' => 4, 'started_on' => 5, 'step' => 6, 'site' => 7],
            array_filter($preview['mapping'], fn ($column) => $column !== null));

        // Step 2: a check writes nothing and reports each line that would fail, with its line number in the file.
        $mapping = array_filter($preview['mapping'], fn ($column) => $column !== null);
        $check = $this->client()->post('/api/v1/production/imports/run', ['target' => 'manufacturing_orders', 'file' => $file(), 'mapping' => $mapping, 'dry_run' => '1'], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.total', 5)->assertJsonCount(3, 'data.errors')->json('data');
        $this->assertSame([4, 5, 6], array_column($check['errors'], 'line'));
        $this->assertStringContainsString('abc', $check['errors'][0]['message']);
        $this->assertSame(0, DB::table('production_orders')->count());
        $this->assertSame(0, DB::table('production_clients')->count());

        // A required information without a column is refused before reading any line.
        $this->client()->post('/api/v1/production/imports/run', ['target' => 'manufacturing_orders', 'file' => $file(), 'mapping' => ['order_number' => 0]], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonStructure(['errors' => ['mapping.article']]);

        // The import writes the valid lines: one OF with its two stages on two sites, and what it points to.
        $this->client()->post('/api/v1/production/imports/run', ['target' => 'manufacturing_orders', 'file' => $file(), 'mapping' => $mapping], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.created', 1)->assertJsonPath('data.updated', 1)->assertJsonPath('data.also_created', ['clients' => 1, 'orders' => 1, 'articles' => 1])
            ->assertJsonCount(3, 'data.errors');
        $of = $this->client()->getJson('/api/v1/production/manufacturing-orders')->assertOk()->assertJsonPath('meta.total', 1)->json('data.0');
        $this->assertSame(['OF-100', 1000, '2026-09-15', 'in_progress', 'Levant Denim', 'CMD-88'],
            [$of['order_number'], $of['quantity_planned'], $of['started_on'], $of['status'], $of['client']['name'], $of['customer_order']['order_number']]);
        $this->assertSame([['cutting', $this->cutting->ulid], ['sewing', $this->sewing->ulid]], array_map(fn ($step) => [$step['step'], $step['site']], $of['steps']));

        // The same file again changes nothing.
        $this->client()->post('/api/v1/production/imports/run', ['target' => 'manufacturing_orders', 'file' => $file(), 'mapping' => $mapping], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.created', 0)->assertJsonPath('data.updated', 0)->assertJsonPath('data.unchanged', 2)->assertJsonPath('data.also_created', []);
        $this->assertSame(1, DB::table('production_orders')->count());

        // A template with the expected headings exists for who starts from nothing; importing needs the right to manage.
        $this->client()->get('/api/v1/production/imports/targets/materials/template')->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $viewer = $this->tokenFor($this->makeMember($this->company, ['management_viewer' => null]), $this->company);
        $this->withToken($viewer)->getJson('/api/v1/production/imports/targets')->assertForbidden();
    }

    private function client(): static
    {
        return $this->withToken($this->tokenFor($this->admin, $this->company));
    }
}
