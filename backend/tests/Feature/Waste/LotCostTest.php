<?php

namespace Tests\Feature\Waste;

use App\Modules\Catalog\Models\WasteType;
use App\Modules\Production\Models\RawMaterial;
use App\Modules\Production\Models\RawMaterialBatch;
use App\Modules\Sites\Models\Site;
use App\Modules\Sites\Models\Zone;
use App\Modules\Tenancy\Models\Company;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * What the material of a waste lot cost, from the batch of raw material it was cut from (scrap reasons: ScrapReasonsTest).
 *
 * The fabric of these tests: 100 m of a 150 cm laize at 200 g/m² weigh 30 kg; bought 300 + 30 of delivery, so 11 a kilo.
 * The batch that prices a lot: its own; else the one its OF consumed; else the last one received by the lot's date.
 */
class LotCostTest extends TestCase
{
    use InteractsWithTenants;
    use RefreshDatabase;

    private Company $company;

    private Site $site;

    private string $token;

    private string $zone;

    private string $type;

    private RawMaterial $fabric;

    private RawMaterialBatch $batch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([ReferenceDataSeeder::class, AuthorizationSeeder::class]);
        $this->company = $this->makeCompany();
        $this->site = $this->makeSite($this->company, 'SFX');
        $this->token = $this->tokenFor($this->makeMember($this->company, ['client_admin' => null]), $this->company);
        [$this->zone, $this->type, $this->fabric, $this->batch] = $this->tenant()->runAs($this->company, function () {
            $fabric = RawMaterial::query()->create(['reference' => '10044', 'name' => 'Néoprène 3 mm', 'unit_id' => DB::table('units')->where('code', 'm')->value('id'),
                'width_cm' => '150', 'grammage_gsm' => '200']);

            return [
                Zone::query()->create(['code' => 'AIRE', 'site_id' => $this->site->id, 'zone_type_id' => DB::table('zone_types')->value('id'), 'name' => 'Aire'])->ulid,
                WasteType::query()->create(['code' => 'CHUTES', 'name' => 'Chutes', 'waste_family_id' => DB::table('waste_families')->value('id'),
                    'default_unit_id' => DB::table('units')->where('code', 'kg')->value('id'), 'activated_at' => now()])->ulid,
                $fabric,
                RawMaterialBatch::query()->create(['raw_material_id' => $fabric->id, 'batch_number' => 'R-1', 'quantity' => '100', 'received_on' => '2026-01-10',
                    'total_amount' => '300', 'delivery_cost' => '30', 'currency_code' => 'TND']),
            ];
        });
    }

    /** @return array<string, mixed> */
    private function lot(array $more = []): array
    {
        // $more wins over the defaults (with `+` the defaults would: a lot asked without its batch would still name it).
        return $this->withToken($this->token)->postJson('/api/v1/lots', array_merge(['site' => $this->site->ulid, 'zone' => $this->zone, 'waste_type' => $this->type,
            'net_weight_kg' => '2.5', 'raw_material' => $this->fabric->ulid, 'raw_material_batch' => $this->batch->ulid], $more))->assertCreated()->json('data');
    }

    public function test_a_lot_cut_from_a_batch_costs_its_weight_of_that_material(): void
    {
        $lot = $this->lot();

        $this->assertSame(['11.0000', '27.500', 'TND', 'lot', 'R-1'],
            [$lot['material_cost']['per_kg'], $lot['material_cost']['value'], $lot['material_cost']['currency'], $lot['material_cost']['basis'], $lot['material_cost']['batch']['batch_number']]);
    }

    public function test_a_lot_that_names_only_its_material_is_priced_by_the_last_batch_received_by_its_date(): void
    {
        // A later delivery, dearer, received after the lot was produced: it does not price that lot.
        $this->tenant()->runAs($this->company, fn () => RawMaterialBatch::query()->create(['raw_material_id' => $this->fabric->id, 'batch_number' => 'R-2',
            'quantity' => '100', 'received_on' => '2026-06-01', 'total_amount' => '900', 'currency_code' => 'TND']));

        $before = $this->lot(['raw_material_batch' => null, 'generated_at' => '2026-03-01T10:00:00Z']);
        $after = $this->lot(['raw_material_batch' => null, 'generated_at' => '2026-07-01T10:00:00Z']);

        $this->assertSame(['latest', 'R-1', '11.0000'], [$before['material_cost']['basis'], $before['material_cost']['batch']['batch_number'], $before['material_cost']['per_kg']]);
        $this->assertSame(['latest', 'R-2', '30.0000'], [$after['material_cost']['basis'], $after['material_cost']['batch']['batch_number'], $after['material_cost']['per_kg']]);
    }

    public function test_without_a_batch_or_a_weight_for_the_material_there_is_no_cost_rather_than_a_wrong_one(): void
    {
        $plain = $this->withToken($this->token)->postJson('/api/v1/lots', ['site' => $this->site->ulid, 'zone' => $this->zone, 'waste_type' => $this->type,
            'net_weight_kg' => '2.5'])->assertCreated()->json('data');
        $this->assertSame([null, null, null], [$plain['material_cost']['per_kg'], $plain['material_cost']['value'], $plain['material_cost']['batch']]);

        // A fabric without its grammage cannot be weighed: no cost.
        $this->tenant()->runAs($this->company, fn () => $this->fabric->forceFill(['grammage_gsm' => null])->save());
        $this->assertNull($this->lot()['material_cost']['per_kg']);
    }

    public function test_a_price_imported_later_costs_the_lots_again_without_touching_their_version(): void
    {
        $lot = $this->lot();
        $version = DB::table('waste_lots')->where('ulid', $lot['ulid'])->value('version');

        $this->tenant()->runAs($this->company, fn () => $this->batch->forceFill(['total_amount' => '600'])->save());

        $row = DB::table('waste_lots')->where('ulid', $lot['ulid'])->first(['material_cost_per_kg', 'material_value', 'version']);
        $this->assertSame(['21.0000', '52.500', $version], [(string) $row->material_cost_per_kg, (string) $row->material_value, $row->version]);
    }

    public function test_correcting_the_origin_costs_the_lot_again(): void
    {
        $lot = $this->withToken($this->token)->postJson('/api/v1/lots', ['site' => $this->site->ulid, 'zone' => $this->zone, 'waste_type' => $this->type,
            'net_weight_kg' => '2.5'])->assertCreated()->json('data');
        $this->assertNull($lot['material_cost']['per_kg']);

        $this->withToken($this->token)->putJson("/api/v1/lots/{$lot['ulid']}/origin", ['raw_material' => $this->fabric->ulid])->assertOk();

        $row = DB::table('waste_lots')->where('ulid', $lot['ulid'])->first(['material_cost_per_kg', 'cost_basis']);
        $this->assertSame(['11.0000', 'latest'], [(string) $row->material_cost_per_kg, $row->cost_basis]);
    }

    public function test_only_people_who_may_see_production_costs_see_the_cost(): void
    {
        $lot = $this->lot();
        $auditor = $this->makeMember($this->company, ['auditor' => null]);

        $seen = $this->withToken($this->tokenFor($auditor, $this->company))->getJson("/api/v1/lots/{$lot['ulid']}")->assertOk()->json('data');

        $this->assertNull($seen['material_cost']);
        $this->withToken($this->tokenFor($auditor, $this->company))->putJson("/api/v1/lots/{$lot['ulid']}/scrap", ['scrap_note' => 'x'])->assertForbidden();
    }

    public function test_the_command_costs_every_lot_again(): void
    {
        $lot = $this->lot();
        DB::table('waste_lots')->where('ulid', $lot['ulid'])->update(['material_cost_per_kg' => null, 'material_value' => null]);

        $this->artisan('lots:costs')->assertSuccessful();

        $this->assertSame('27.500', (string) DB::table('waste_lots')->where('ulid', $lot['ulid'])->value('material_value'));
    }

    /**
     * An OF of an article cut from the fabric, and a lot of its waste that names the fabric but no batch, produced after
     * a dearer delivery R-2 (30 a kilo): until the OF says what it consumed, R-2 prices the lot.
     *
     * @return array{0: string, 1: string} the OF's ULID, the lot's ULID
     */
    private function lotOfAnOf(): array
    {
        $post = fn (string $path, array $body) => $this->withToken($this->token)->postJson("/api/v1/production/{$path}", $body)->assertCreated()->json('data');
        $this->tenant()->runAs($this->company, fn () => RawMaterialBatch::query()->create(['raw_material_id' => $this->fabric->id, 'batch_number' => 'R-2',
            'quantity' => '100', 'received_on' => '2026-06-01', 'total_amount' => '900', 'currency_code' => 'TND']));
        $article = $post('articles', ['reference' => 'TOP', 'name' => 'Top', 'materials' => [['material' => $this->fabric->ulid, 'quantity_per_piece' => '0.5']]]);
        $of = $post('manufacturing-orders', ['order_number' => 'OF-T', 'article' => $article['ulid'], 'quantity_planned' => 100]);
        $lot = $this->lot(['raw_material_batch' => null, 'production_order' => $of['ulid'], 'generated_at' => '2026-07-01T10:00:00Z']);
        $this->assertSame(['latest', 'R-2'], [$lot['material_cost']['basis'], $lot['material_cost']['batch']['batch_number']]);

        return [$of['ulid'], $lot['ulid']];
    }

    /** @return list<string|null> basis, batch number and cost per kg of a lot, as stored */
    private function costOf(string $lot): array
    {
        $row = DB::table('waste_lots as l')->leftJoin('raw_material_batches as b', 'b.id', '=', 'l.cost_batch_id')->where('l.ulid', $lot)
            ->first(['l.cost_basis', 'b.batch_number', 'l.material_cost_per_kg']);

        return [$row->cost_basis, $row->batch_number, $row->material_cost_per_kg === null ? null : (string) $row->material_cost_per_kg];
    }

    public function test_what_the_of_consumed_prices_its_lots_once_it_is_entered(): void
    {
        [$of, $lot] = $this->lotOfAnOf();

        $this->withToken($this->token)->putJson("/api/v1/production/manufacturing-orders/{$of}/consumptions",
            ['consumptions' => [['material' => $this->fabric->ulid, 'batch' => $this->batch->ulid, 'quantity' => '10']]])->assertOk();

        $this->assertSame(['consumption', 'R-1', '11.0000'], $this->costOf($lot));
    }

    public function test_imported_consumptions_and_batches_price_the_lots_again_but_not_a_dry_run(): void
    {
        [, $lot] = $this->lotOfAnOf();
        $run = fn (string $target, string $csv, array $mapping, bool $dry = false) => $this->withToken($this->token)->post('/api/v1/production/imports/run',
            ['target' => $target, 'file' => UploadedFile::fake()->createWithContent("{$target}.csv", $csv), 'mapping' => $mapping] + ($dry ? ['dry_run' => '1'] : []),
            ['Accept' => 'application/json'])->assertOk()->json('data');
        $consumed = "OF,Matière,Consommation,Lot\nOF-T,10044,10,R-1\n";
        $mapping = ['manufacturing_order' => 0, 'material' => 1, 'consumed_quantity' => 2, 'batch_number' => 3];

        $this->assertSame(1, $run('consumptions', $consumed, $mapping, dry: true)['created']);
        $this->assertSame(['latest', 'R-2', '30.0000'], $this->costOf($lot), 'a dry run changes nothing');

        $this->assertSame(1, $run('consumptions', $consumed, $mapping)['created']);
        $this->assertSame(['consumption', 'R-1', '11.0000'], $this->costOf($lot));

        // The materials file corrects the quantity of R-1 (written without the batch model): 330 for 15 kg, 22 a kilo.
        $this->assertSame(1, $run('materials', "Référence,Lot,Quantité lot\n10044,R-1,50\n", ['reference' => 0, 'batch_number' => 1, 'batch_quantity' => 2])['updated']);
        $this->assertSame(['consumption', 'R-1', '22.0000'], $this->costOf($lot));
    }

    public function test_a_price_typed_on_the_lot_costs_its_material_quantity_like_a_scrap_report(): void
    {
        // 2.156 m at 3.50 a metre; a metre of this fabric weighs 1.5 × 200 g = 0.3 kg, so 11.6666 a kilo.
        $lot = $this->lot(['raw_material_batch' => null, 'material_quantity' => '2.156', 'material_unit' => 'm', 'material_unit_price' => '3.5']);

        $this->assertSame(['entered', '3.50000', 'm', '3.50000', '7.546', '11.6666', 'TND', null],
            [$lot['material_cost']['basis'], $lot['material_cost']['unit_cost'], $lot['material_cost']['unit'], $lot['material_cost']['unit_price'],
                $lot['material_cost']['value'], $lot['material_cost']['per_kg'], $lot['material_cost']['currency'], $lot['material_cost']['batch']]);
        $this->assertSame(['quantity' => '2.1560', 'unit' => 'm'], $lot['material_quantity']);
    }

    public function test_a_material_quantity_without_a_typed_price_is_priced_by_the_batch_per_unit(): void
    {
        // The batch: 300 + 30 for 100 m, 3.30 a metre.
        $metres = $this->lot(['material_quantity' => '2']);
        $this->assertSame(['lot', '3.30000', 'm', '6.600', '11.0000'],
            [$metres['material_cost']['basis'], $metres['material_cost']['unit_cost'], $metres['material_cost']['unit'], $metres['material_cost']['value'], $metres['material_cost']['per_kg']]);

        // The same fabric counted in kilograms: 11 a kilo.
        $kilos = $this->lot(['material_quantity' => '2', 'material_unit' => 'kg']);
        $this->assertSame(['11.00000', 'kg', '22.000'], [$kilos['material_cost']['unit_cost'], $kilos['material_cost']['unit'], $kilos['material_cost']['value']]);

        // Without a quantity, the weight prices it as before; the unit cost is the material's.
        $weighed = $this->lot();
        $this->assertSame(['3.30000', 'm', '27.500'], [$weighed['material_cost']['unit_cost'], $weighed['material_cost']['unit'], $weighed['material_cost']['value']]);
    }

    public function test_only_people_who_may_see_production_costs_may_type_a_price(): void
    {
        $operator = $this->tokenFor($this->makeMember($this->company, ['workshop_operator' => $this->site]), $this->company);

        $this->withToken($operator)->postJson('/api/v1/lots', ['site' => $this->site->ulid, 'zone' => $this->zone, 'waste_type' => $this->type,
            'net_weight_kg' => '2.5', 'raw_material' => $this->fabric->ulid, 'material_unit_price' => '3.5'])
            ->assertUnprocessable()->assertJsonValidationErrors('material_unit_price');
        // The quantity is not a cost: they may give it.
        $this->withToken($operator)->postJson('/api/v1/lots', ['site' => $this->site->ulid, 'zone' => $this->zone, 'waste_type' => $this->type,
            'net_weight_kg' => '2.5', 'raw_material' => $this->fabric->ulid, 'material_quantity' => '2', 'material_unit' => 'm'])->assertCreated();
    }

    public function test_the_origin_dialog_sets_the_quantity_and_price_and_keeps_what_it_does_not_send(): void
    {
        $lot = $this->lot(['raw_material_batch' => null]);

        $this->withToken($this->token)->putJson("/api/v1/lots/{$lot['ulid']}/origin", ['raw_material' => $this->fabric->ulid,
            'material_quantity' => '4', 'material_unit' => 'm', 'material_unit_price' => '2.25'])->assertOk();
        $row = DB::table('waste_lots')->where('ulid', $lot['ulid'])->first(['material_value', 'cost_basis', 'material_unit_cost']);
        $this->assertSame(['9.000', 'entered', '2.25000'], [(string) $row->material_value, $row->cost_basis, (string) $row->material_unit_cost]);

        // An older screen that only sends the origin leaves the quantity and the price alone.
        $this->withToken($this->token)->putJson("/api/v1/lots/{$lot['ulid']}/origin", ['raw_material' => $this->fabric->ulid])->assertOk();
        $this->assertSame('9.000', (string) DB::table('waste_lots')->where('ulid', $lot['ulid'])->value('material_value'));

        // Cleared: the batches price it again.
        $this->withToken($this->token)->putJson("/api/v1/lots/{$lot['ulid']}/origin", ['raw_material' => $this->fabric->ulid, 'material_unit_price' => null])->assertOk();
        $row = DB::table('waste_lots')->where('ulid', $lot['ulid'])->first(['material_value', 'cost_basis']);
        $this->assertSame(['13.200', 'latest'], [(string) $row->material_value, $row->cost_basis]);
    }

    public function test_a_split_shares_the_material_quantity_by_weight(): void
    {
        $lot = $this->lot(['material_quantity' => '10', 'material_unit' => 'm', 'net_weight_kg' => '4']);

        $parts = $this->withToken($this->token)->postJson("/api/v1/lots/{$lot['ulid']}/split", ['outputs' => [['net_weight_kg' => '1'], ['net_weight_kg' => '3']]])
            ->assertSuccessful()->json('data');

        $this->assertSame(['2.5000', '7.5000'], array_map(fn ($part) => $part['material_quantity']['quantity'], $parts));
        $this->assertSame(['8.250', '24.750'], array_map(fn ($part) => $part['material_cost']['value'], $parts));
    }

    public function test_a_material_counted_by_area_is_costed_in_its_unit_or_another_area(): void
    {
        $film = $this->tenant()->runAs($this->company, function () {
            $film = RawMaterial::query()->create(['reference' => '10000-002', 'name' => 'Film adhésif', 'unit_id' => DB::table('units')->where('code', 'ft2')->value('id')]);
            // As an import records a price of 1.84587 $ the square foot for one: its total has three decimals.
            RawMaterialBatch::query()->create(['raw_material_id' => $film->id, 'batch_number' => 'COUT-STD', 'quantity' => '1', 'received_on' => '2026-01-01',
                'unit_price' => '1.84587', 'total_amount' => '1.845', 'currency_code' => 'USD']);

            return $film;
        });

        $inFeet = $this->lot(['raw_material' => $film->ulid, 'raw_material_batch' => null, 'net_weight_kg' => null, 'material_quantity' => '0.5']);
        $inMetres = $this->lot(['raw_material' => $film->ulid, 'raw_material_batch' => null, 'net_weight_kg' => null, 'material_quantity' => '0.1', 'material_unit' => 'm2']);

        $this->assertSame(['1.84587', '0.922', 'ft2', 'USD'], [$inFeet['material_cost']['unit_cost'], $inFeet['material_cost']['value'], $inFeet['material_cost']['unit'], $inFeet['material_cost']['currency']]);
        // A square metre is 10.7639104 square feet.
        $this->assertSame(['19.86877', '1.986', 'm2'], [$inMetres['material_cost']['unit_cost'], $inMetres['material_cost']['value'], $inMetres['material_cost']['unit']]);
    }

    public function test_a_unit_price_keeps_its_decimals_when_the_total_of_the_batch_rounds_it(): void
    {
        $label = $this->tenant()->runAs($this->company, function () {
            $label = RawMaterial::query()->create(['reference' => '13-2562', 'name' => 'Étiquette', 'unit_id' => DB::table('units')->where('code', 'piece')->value('id')]);
            RawMaterialBatch::query()->create(['raw_material_id' => $label->id, 'batch_number' => 'COUT-STD', 'quantity' => '1', 'received_on' => '2026-01-01',
                'unit_price' => '0.01751', 'total_amount' => '0.017', 'currency_code' => 'USD']);

            return $label;
        });

        $lot = $this->lot(['raw_material' => $label->ulid, 'raw_material_batch' => null, 'net_weight_kg' => null, 'material_quantity' => '200']);

        $this->assertSame(['0.01751', '3.502'], [$lot['material_cost']['unit_cost'], $lot['material_cost']['value']]);
    }

    public function test_a_waste_is_not_counted_by_area(): void
    {
        $this->withToken($this->token)->postJson('/api/v1/lots', ['site' => $this->site->ulid, 'zone' => $this->zone, 'waste_type' => $this->type, 'quantity' => '3', 'unit' => 'm2'])
            ->assertUnprocessable()->assertJsonValidationErrors(['unit']);
    }
}
