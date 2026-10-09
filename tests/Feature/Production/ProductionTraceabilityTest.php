<?php

namespace Tests\Feature\Production;

use App\Modules\Catalog\Models\WasteType;
use App\Modules\Identity\Models\User;
use App\Modules\Sites\Models\Site;
use App\Modules\Sites\Models\Zone;
use App\Modules\Tenancy\Models\Company;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Three kinds of lot, never mixed: a RECEIVED lot is a delivery of a raw material by a supplier; a PRODUCED lot is
 * finished goods made by an OF for its client; a WASTE lot is waste. And the money that follows them: what a
 * consumption cost, what an OF cost, what it is worth, and a gain only when both sides are known.
 *
 * The figures: a material in kg from supplier A. Lot L1: 100 kg at 10 = 1 000. Lot L2, delivered by supplier B: 50 kg
 * for 600 plus 50 of delivery = 13 per kg. Average of the material: (1 000 + 650) / 150 = 11.
 */
class ProductionTraceabilityTest extends TestCase
{
    use InteractsWithTenants;

    private Company $company;

    private Site $site;

    private User $admin;

    /** @var array<string, array<string, mixed>> */
    private array $r = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([ReferenceDataSeeder::class, AuthorizationSeeder::class]);
        $this->company = $this->makeCompany();
        $this->site = $this->makeSite($this->company, 'NAB');
        $this->admin = $this->makeMember($this->company, ['client_admin' => null]);

        $post = fn (string $path, array $body) => $this->client()->postJson("/api/v1/production/{$path}", $body)->assertCreated()->json('data');
        $this->r['client'] = $post('clients', ['code' => 'LEV', 'name' => 'Levant Denim']);
        $this->r['a'] = $post('suppliers', ['code' => 'A', 'name' => 'Supplier A']);
        $this->r['b'] = $post('suppliers', ['code' => 'B', 'name' => 'Supplier B']);
        // 1. A material with its default supplier.
        $this->r['material'] = $post('materials', ['reference' => 'DEN', 'name' => 'Denim', 'category' => 'Tissu', 'supplier' => $this->r['a']['ulid'], 'unit' => 'kg']);
        $this->r['article'] = $post('articles', ['reference' => 'JEAN', 'name' => 'Jean', 'client' => $this->r['client']['ulid'],
            'materials' => [['material' => $this->r['material']['ulid'], 'quantity_per_piece' => '0.02']]]);
        // 1 000 pieces at 5, less 500 of discount: the order is worth 4 500, a piece 4.5 net.
        $this->r['order'] = $post('orders', ['order_number' => 'CMD-1', 'client' => $this->r['client']['ulid'], 'discount_amount' => '500',
            'lines' => [['article' => $this->r['article']['ulid'], 'quantity' => 1000, 'unit_price' => '5']]]);
        $this->r['of'] = $post('manufacturing-orders', ['order_number' => 'OF-1', 'article' => $this->r['article']['ulid'], 'customer_order' => $this->r['order']['ulid'],
            'quantity_planned' => 1000, 'status' => 'in_progress', 'started_on' => '2026-09-01', 'steps' => [['step' => 'cutting', 'site' => $this->site->ulid]]]);
    }

    private function client(?User $user = null, ?Company $company = null): static
    {
        return $this->withToken($this->tokenFor($user ?? $this->admin, $company ?? $this->company));
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>} the two received lots
     */
    private function receive(): array
    {
        $path = "/api/v1/production/materials/{$this->r['material']['ulid']}/batches";
        $this->client()->postJson($path, ['batch_number' => 'L1', 'quantity' => '100', 'unit_price' => '10', 'received_on' => '2026-08-01'])->assertCreated();
        $material = $this->client()->postJson($path, ['batch_number' => 'L2', 'supplier' => $this->r['b']['ulid'], 'supplier_batch_reference' => 'B-778', 'quantity' => '50',
            'total_amount' => '600', 'delivery_cost' => '50', 'delivery_note' => 'BL-9', 'received_on' => '2026-08-10'])->assertCreated()->json('data');
        $lots = collect($material['batches'])->keyBy('batch_number');

        return [$lots['L1'], $lots['L2']];
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, mixed> the OF
     */
    private function consume(string $of, array $lines): array
    {
        return $this->client()->putJson("/api/v1/production/manufacturing-orders/{$of}/consumptions", ['consumptions' => $lines])->assertOk()->json('data');
    }

    public function test_each_received_lot_has_its_own_supplier_and_price_whatever_the_material_says_later(): void
    {
        [$l1, $l2] = $this->receive();

        // 2. Two deliveries of the same material, from two suppliers, at two prices.
        $this->assertSame(['Supplier A', '10.0000', '1000.000', '10.0000', '1000.000', $this->company->currency_code],
            [$l1['supplier']['name'], $l1['unit_price'], $l1['total_amount'], $l1['unit_cost'], $l1['purchase_value'], $l1['currency_code']]);
        $this->assertSame(['Supplier B', 'B-778', '12.0000', '600.000', '13.0000', '650.000'],
            [$l2['supplier']['name'], $l2['supplier_batch_reference'], $l2['unit_price'], $l2['total_amount'], $l2['unit_cost'], $l2['purchase_value']]);

        // A total that contradicts quantity × price is refused, not silently replaced.
        $this->client()->postJson("/api/v1/production/materials/{$this->r['material']['ulid']}/batches", ['batch_number' => 'L3', 'quantity' => '10', 'unit_price' => '2', 'total_amount' => '50'])
            ->assertUnprocessable()->assertJsonValidationErrors(['total_amount']);
        $this->client()->postJson("/api/v1/production/materials/{$this->r['material']['ulid']}/batches", ['batch_number' => 'L1'])->assertUnprocessable()->assertJsonValidationErrors(['batch_number']);

        // 3. The material changes its default supplier: the lots already received keep theirs.
        $this->client()->putJson("/api/v1/production/materials/{$this->r['material']['ulid']}", ['reference' => 'DEN', 'name' => 'Denim', 'supplier' => $this->r['b']['ulid'], 'unit' => 'kg'])
            ->assertOk()->assertJsonPath('data.supplier.name', 'Supplier B');
        $material = $this->client()->getJson("/api/v1/production/materials/{$this->r['material']['ulid']}")->assertOk()->assertJsonPath('data.average_unit_cost', '11.0000')
            ->assertJsonPath('data.summary.received', '150.000')->assertJsonPath('data.summary.purchase_value', '1650.000')->assertJsonPath('data.summary.unpriced_lots', 0)->json('data');
        $this->assertSame(['L1' => 'Supplier A', 'L2' => 'Supplier B'], collect($material['batches'])->mapWithKeys(fn ($lot) => [$lot['batch_number'] => $lot['supplier']['name']])->sortKeys()->all());
        // A lot received from now on is proposed the new supplier.
        $this->client()->postJson("/api/v1/production/materials/{$this->r['material']['ulid']}/batches", ['batch_number' => 'L4'])->assertCreated();
        $this->assertSame($this->r['b']['code'], DB::table('raw_material_batches as b')->join('production_suppliers as s', 's.id', '=', 'b.production_supplier_id')->where('b.batch_number', 'L4')->value('s.code'));

        // Correcting a lot: its supplier, its price. All received lots can be searched across materials.
        $this->client()->putJson("/api/v1/production/batches/{$l1['ulid']}", ['batch_number' => 'L1', 'supplier' => $this->r['a']['ulid'], 'quantity' => '100', 'unit_price' => '10.5'])
            ->assertOk();
        $this->client()->getJson('/api/v1/production/received-lots?supplier='.$this->r['a']['ulid'])->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.batch_number', 'L1')->assertJsonPath('data.0.total_amount', '1050.000')->assertJsonPath('data.0.material.reference', 'DEN');
        $this->client()->getJson('/api/v1/production/received-lots?priced=no')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.batch_number', 'L4');

        // Nothing here is a stock.
        foreach (['stock', 'balance', 'zone_id', 'site_id'] as $column) {
            $this->assertFalse(Schema::hasColumn('raw_material_batches', $column));
        }
    }

    public function test_an_order_consumes_from_several_lots_and_each_lot_is_charged_for_what_was_taken(): void
    {
        [$l1, $l2] = $this->receive();
        $material = $this->r['material']['ulid'];

        // 4 and 5. 12 kg of L1, 8 kg of L2, and 3 kg nobody tied to a lot.
        $of = $this->consume($this->r['of']['ulid'], [
            ['material' => $material, 'batch' => $l1['ulid'], 'quantity' => '12', 'step' => 'cutting', 'site' => $this->site->ulid, 'consumed_on' => '2026-09-02'],
            ['material' => $material, 'batch' => $l2['ulid'], 'quantity' => '8'],
            ['material' => $material, 'quantity' => '3', 'notes' => 'Fin de rouleau'],
        ]);
        $row = $of['materials'][0];
        $this->assertSame(['20.000', '23.000', '3.000', 15.0, '20.000', '3.000'], [$row['planned'], $row['consumed'], $row['variance'], (float) $row['variance_pct'], $row['allocated'], $row['unallocated']]);
        // OF → material consumed → received lot → supplier, line by line.
        $this->assertSame([['L1', 'Supplier A', '12.000', '120.000', 'lot'], ['L2', 'Supplier B', '8.000', '104.000', 'lot'], [null, null, '3.000', '33.000', 'average']],
            array_map(fn ($line) => [$line['batch_number'], $line['supplier']['name'] ?? null, $line['quantity'], $line['cost'], $line['basis']], $row['lines']));
        $this->assertSame(['cutting', $this->site->ulid, '2026-09-02'], [$row['lines'][0]['step'], $row['lines'][0]['site'], $row['lines'][0]['consumed_on']]);
        // The lot that is not known is estimated at the average of the material, and counted apart from what is known.
        $this->assertSame(['known' => '224.000', 'estimated' => '33.000', 'total' => '257.000', 'complete' => true, 'unknown_lines' => 0], $of['finance']['material_cost']);
        $this->assertContains('estimated_material_cost', $of['finance']['warnings']);
        $this->assertSame('220.000', $of['finance']['planned_material_cost']);

        // 10 and 11. A second OF takes 30 kg of L1: it costs 300, not the 1 000 the lot was bought for.
        $second = $this->client()->postJson('/api/v1/production/manufacturing-orders', ['order_number' => 'OF-2', 'article' => $this->r['article']['ulid'], 'quantity_planned' => 500])->assertCreated()->json('data');
        $second = $this->consume($second['ulid'], [['material' => $material, 'batch' => $l1['ulid'], 'quantity' => '30']]);
        $this->assertSame('300.000', $second['finance']['material_cost']['total']);
        $lot = $this->client()->getJson('/api/v1/production/received-lots?search=L1')->assertOk()->json('data.0');
        $this->assertSame(['42.000', '420.000', '1000.000', false], [$lot['consumed'], $lot['consumed_value'], $lot['purchase_value'], $lot['over_consumed']]);
        // The first OF is not charged a cent more because another one used the same lot.
        $this->client()->getJson("/api/v1/production/manufacturing-orders/{$this->r['of']['ulid']}")->assertJsonPath('data.finance.material_cost.known', '224.000');
        // More taken than the lot held is flagged, not hidden.
        $this->consume($second['ulid'], [['material' => $material, 'batch' => $l1['ulid'], 'quantity' => '95']]);
        $this->assertTrue($this->client()->getJson('/api/v1/production/received-lots?search=L1')->json('data.0.over_consumed'));

        // A lot of another material cannot be named, and a material without any price has an unknown cost — never zero.
        $other = $this->client()->postJson('/api/v1/production/materials', ['reference' => 'FIL', 'name' => 'Fil', 'unit' => 'kg'])->assertCreated()->json('data');
        $this->client()->putJson("/api/v1/production/manufacturing-orders/{$second['ulid']}/consumptions", ['consumptions' => [['material' => $other['ulid'], 'batch' => $l1['ulid'], 'quantity' => '1']]])
            ->assertUnprocessable()->assertJsonValidationErrors(['consumptions']);
        $unknown = $this->consume($second['ulid'], [['material' => $material, 'batch' => $l1['ulid'], 'quantity' => '30'], ['material' => $other['ulid'], 'quantity' => '5']]);
        $this->assertSame(['known' => '300.000', 'estimated' => '0.000', 'total' => '300.000', 'complete' => false, 'unknown_lines' => 1], $unknown['finance']['material_cost']);
        $this->assertSame(['unknown', 'no_price', null], [$unknown['materials'][1]['lines'][0]['basis'], $unknown['materials'][1]['lines'][0]['reason'], $unknown['materials'][1]['cost']]);
        $this->assertNull($unknown['finance']['unit_cost']);

        // Where the material went, from its own page.
        $history = $this->client()->getJson("/api/v1/production/materials/{$material}")->assertOk()->assertJsonPath('data.summary.orders', 2)
            ->assertJsonPath('data.summary.unallocated', '3.000')->json('data.consumptions');
        $this->assertEqualsCanonicalizing(['OF-1', 'OF-1', 'OF-1', 'OF-2'], array_column($history, 'order_number'));
    }

    public function test_an_order_makes_several_produced_lots_for_its_client_and_none_has_a_supplier(): void
    {
        $path = "/api/v1/production/manufacturing-orders/{$this->r['of']['ulid']}/produced-lots";
        // 6. Two batches of the same OF, each identifiable, both for the client and the order of the OF.
        $this->client()->postJson($path, ['lot_number' => 'LF-A', 'quantity' => 400, 'produced_on' => '2026-09-10', 'site' => $this->site->ulid, 'step' => 'cutting'])->assertCreated();
        $of = $this->client()->postJson($path, ['lot_number' => 'LF-B', 'quantity' => 500, 'produced_on' => '2026-09-12', 'supplier' => $this->r['a']['ulid']])->assertCreated()
            ->assertJsonPath('data.quantity_produced', 900)->assertJsonPath('data.produced', ['quantity' => 900, 'delivered' => 0, 'source' => 'lots'])->json('data');
        $this->assertSame([['LF-A', 400, 'Levant Denim', 'CMD-1', 'OF-1', 'JEAN'], ['LF-B', 500, 'Levant Denim', 'CMD-1', 'OF-1', 'JEAN']],
            array_map(fn ($lot) => [$lot['lot_number'], $lot['quantity'], $lot['client']['name'], $lot['customer_order']['order_number'], $lot['manufacturing_order']['order_number'], $lot['article']['reference']], $of['produced_lots']));

        // 7. A produced lot has no supplier: not in what is stored, not in what is answered, even when one is sent.
        $this->assertFalse(Schema::hasColumn('produced_lots', 'production_supplier_id'));
        $this->assertArrayNotHasKey('supplier', $of['produced_lots'][1]);
        foreach (['stock', 'balance', 'zone_id'] as $column) {
            $this->assertFalse(Schema::hasColumn('produced_lots', $column));
        }

        $this->client()->postJson($path, ['lot_number' => 'LF-A', 'quantity' => 10])->assertUnprocessable()->assertJsonValidationErrors(['lot_number']);
        $this->client()->postJson($path, ['lot_number' => 'LF-C', 'quantity' => 0])->assertUnprocessable()->assertJsonValidationErrors(['quantity']);

        // Delivered, then one cancelled: the pieces of the OF follow its live lots.
        $a = $of['produced_lots'][0]['ulid'];
        $this->client()->putJson("/api/v1/production/produced-lots/{$a}", ['lot_number' => 'LF-A', 'quantity' => 400, 'status' => 'delivered', 'delivered_on' => '2026-09-15'])->assertOk()
            ->assertJsonPath('data.produced.delivered', 400)->assertJsonPath('data.produced_lots.0.delivered_on', '2026-09-15');
        $this->client()->putJson("/api/v1/production/produced-lots/{$of['produced_lots'][1]['ulid']}", ['lot_number' => 'LF-B', 'quantity' => 500, 'status' => 'cancelled'])->assertOk()
            ->assertJsonPath('data.quantity_produced', 400);
        $this->assertDatabaseHas('produced_lots', ['lot_number' => 'LF-B', 'status' => 'cancelled']);

        $this->client()->getJson('/api/v1/production/produced-lots?client='.$this->r['client']['ulid'].'&status=delivered')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.lot_number', 'LF-A');
        $this->client()->getJson('/api/v1/production/produced-lots?search=CMD-1')->assertOk()->assertJsonPath('meta.total', 2);
    }

    public function test_costs_revenue_and_gain_are_given_only_as_far_as_they_are_known(): void
    {
        [$l1, $l2] = $this->receive();
        $material = $this->r['material']['ulid'];
        $of = $this->r['of']['ulid'];
        $this->consume($of, [['material' => $material, 'batch' => $l1['ulid'], 'quantity' => '12'], ['material' => $material, 'batch' => $l2['ulid'], 'quantity' => '8'], ['material' => $material, 'quantity' => '3']]);

        // Nothing produced yet: there is a cost, there is an order value, there is no gain.
        $finance = $this->client()->getJson("/api/v1/production/manufacturing-orders/{$of}")->json('data.finance');
        $this->assertSame(['4.5000', '4500.000', null, '4500.000'], [$finance['revenue']['net_unit_price'], $finance['revenue']['planned_value'], $finance['revenue']['produced_value'], $finance['revenue']['order_value']]);
        $this->assertSame([null, ['no_production']], [$finance['gain']['amount'], $finance['gain']['missing']]);

        $this->client()->postJson("/api/v1/production/manufacturing-orders/{$of}/produced-lots", ['lot_number' => 'LF-A', 'quantity' => 900])->assertCreated();
        $this->client()->postJson("/api/v1/production/manufacturing-orders/{$of}/expenses", ['category' => 'subcontracting', 'description' => 'Délavage', 'amount' => '100', 'reference' => 'FA-1',
            'supplier' => $this->r['b']['ulid']])->assertCreated()->assertJsonPath('data.expenses.0.supplier.name', 'Supplier B');
        $this->client()->postJson("/api/v1/production/manufacturing-orders/{$of}/expenses", ['category' => 'bribe', 'description' => 'x', 'amount' => '1'])->assertUnprocessable();
        // 12. What was invoiced is recorded apart from what was ordered.
        $order = $this->client()->postJson("/api/v1/production/orders/{$this->r['order']['ulid']}/invoices", ['kind' => 'invoice', 'number' => 'FV-1', 'amount' => '2000', 'issued_on' => '2026-09-20'])->assertCreated()->json('data');
        $this->assertSame(['4500.000', '2000.000', '2500.000', '500.000'], [$order['order_value'], $order['invoiced'], $order['uninvoiced'], $order['discount_amount']]);

        // 13. Recorded gain = value of the production (900 × 4.5) − recorded costs (257 of material + 100 of expenses).
        $finance = $this->client()->getJson("/api/v1/production/manufacturing-orders/{$of}")->json('data.finance');
        $this->assertSame(['100.000', '357.000', true, '0.3966'], [$finance['expenses']['total'], $finance['total_cost'], $finance['cost_complete'], $finance['unit_cost']]);
        $this->assertSame(['4050.000', '2000.000', 'of', '2050.000'], [$finance['revenue']['produced_value'], $finance['revenue']['invoiced'], $finance['revenue']['invoiced_scope'], $finance['revenue']['uninvoiced']]);
        $this->assertSame(['revenue' => '4050.000', 'cost' => '357.000', 'amount' => '3693.000', 'margin_pct' => 91.2, 'estimated' => true, 'missing' => []], $finance['gain']);

        // 14. A second OF on the same order: the invoice of the order is no longer anybody's in particular, and is counted once.
        $second = $this->client()->postJson('/api/v1/production/manufacturing-orders', ['order_number' => 'OF-2', 'article' => $this->r['article']['ulid'], 'customer_order' => $this->r['order']['ulid'],
            'quantity_planned' => 100, 'quantity_produced' => 100, 'started_on' => '2026-09-05'])->assertCreated()->json('data');
        $this->consume($second['ulid'], [['material' => $material, 'batch' => $l1['ulid'], 'quantity' => '2']]);
        foreach ([$of, $second['ulid']] as $ulid) {
            $this->client()->getJson("/api/v1/production/manufacturing-orders/{$ulid}")->assertJsonPath('data.finance.revenue.invoiced_scope', 'order')->assertJsonPath('data.finance.revenue.invoiced', '2000.000');
        }
        // A credit note that names the first OF is that OF's.
        $this->client()->postJson("/api/v1/production/orders/{$this->r['order']['ulid']}/invoices", ['kind' => 'credit_note', 'number' => 'AV-1', 'amount' => '300', 'manufacturing_order' => $of])
            ->assertCreated()->assertJsonPath('data.invoiced', '1700.000');
        $this->client()->getJson("/api/v1/production/manufacturing-orders/{$of}")->assertJsonPath('data.finance.revenue.invoiced_scope', 'of')->assertJsonPath('data.finance.revenue.invoiced', '-300.000');

        $report = $this->client()->getJson('/api/v1/production/reports/finance?from=2026-09-01&to=2026-09-30')->assertOk()->json('data');
        $this->assertSame([2, '100.000', '377.000', '4500.000', '1700.000', 2], [$report['totals']['orders'], $report['totals']['expenses'], $report['totals']['total_cost'],
            $report['totals']['produced_value'], $report['totals']['invoiced'], $report['totals']['gain_orders']]);
        // 3 693 + (450 − 20): the gains of the two orders, each counted once.
        $this->assertSame('4123.000', $report['totals']['gain']);
        $this->assertSame([['Supplier A', '140.000'], ['Supplier B', '104.000'], [null, '0.000']], array_map(fn ($row) => [$row['supplier'], $row['cost']], $report['by_supplier']));
        $this->assertSame(['22.000', '25.000', '3.000'], [$report['by_material'][0]['planned'], $report['by_material'][0]['consumed'], $report['by_material'][0]['unallocated']]);
        $this->client()->get('/api/v1/production/reports/finance/export?from=2026-09-01&to=2026-09-30')->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        // Without a selling price, or with a consumption nobody can price: no gain is shown, and the reason is given.
        $this->client()->putJson("/api/v1/production/orders/{$this->r['order']['ulid']}", ['order_number' => 'CMD-1', 'client' => $this->r['client']['ulid'],
            'lines' => [['article' => $this->r['article']['ulid'], 'quantity' => 1000]]])->assertOk()->assertJsonPath('data.order_value', null);
        $this->client()->getJson("/api/v1/production/manufacturing-orders/{$of}")->assertJsonPath('data.finance.gain.amount', null)->assertJsonPath('data.finance.gain.missing', ['no_price'])
            ->assertJsonPath('data.finance.total_cost', '357.000');

        // Invoices and expenses are corrected or removed; the removal is in the history.
        $expense = $this->client()->getJson("/api/v1/production/manufacturing-orders/{$of}")->json('data.expenses.0.ulid');
        $this->client()->deleteJson("/api/v1/production/expenses/{$expense}")->assertOk()->assertJsonPath('data.finance.expenses.total', '0.000');
        $this->assertTrue(DB::table('audit_logs')->where('event', 'deleted')->where('auditable_ulid', $expense)->exists());
    }

    public function test_waste_stays_in_the_waste_workflow_whether_it_names_an_order_or_not(): void
    {
        [$type, $zone, $storage] = $this->tenant()->runAs($this->company, fn () => [
            WasteType::query()->create(['code' => 'CHUTES', 'name' => 'Chutes', 'waste_family_id' => DB::table('waste_families')->value('id'),
                'default_unit_id' => DB::table('units')->where('code', 'kg')->value('id'), 'activated_at' => now()]),
            Zone::query()->create(['site_id' => $this->site->id, 'zone_type_id' => DB::table('zone_types')->where('code', 'cutting')->value('id'), 'code' => 'COUPE', 'name' => 'Coupe']),
            Zone::query()->create(['site_id' => $this->site->id, 'zone_type_id' => DB::table('zone_types')->where('code', 'waste_storage')->value('id'), 'code' => 'AIRE', 'name' => 'Aire déchets']),
        ]);
        [$l1] = $this->receive();
        $this->consume($this->r['of']['ulid'], [['material' => $this->r['material']['ulid'], 'batch' => $l1['ulid'], 'quantity' => '23']]);
        $lot = fn (array $more, ?Zone $in = null) => $this->client()->postJson('/api/v1/lots', ['site' => $this->site->ulid, 'zone' => ($in ?? $zone)->ulid, 'waste_type' => $type->ulid, 'net_weight_kg' => '4.6'] + $more)->assertCreated()->json('data');

        // 8. A waste lot that names the OF: an ordinary waste lot, seen from the OF with its stage.
        $attached = $lot(['production_order' => $this->r['of']['ulid']]);
        $waste = $this->client()->getJson("/api/v1/production/manufacturing-orders/{$this->r['of']['ulid']}")->assertOk()->json('data.waste');
        $this->assertSame([1, '4.600', '23.000', 20.0, true], [$waste['lots'], $waste['kg'], $waste['consumed_kg'], (float) $waste['waste_rate_pct'], $waste['can_view_lots']]);
        $this->assertSame([$attached['ulid'], $attached['lot_number'], 'Chutes', 'DEN'], [$waste['rows'][0]['ulid'], $waste['rows'][0]['lot_number'], $waste['rows'][0]['waste_type'], $waste['rows'][0]['material']]);
        // The OF says where the waste comes from; it does not say which received lot unless the operator did.
        $this->assertNull($waste['rows'][0]['batch_number']);

        // 9. A waste lot that names nothing: in the register like any other, and "not attributed" for production.
        // (Recorded in a zone that stands for no stage: in the cutting workshop it would be shared between the OFs being cut.)
        $free = $lot([], $storage);
        $this->assertEqualsCanonicalizing([$attached['ulid'], $free['ulid']], array_column($this->client()->getJson('/api/v1/lots')->assertOk()->json('data'), 'ulid'));
        $today = now()->toDateString();
        $stats = $this->client()->getJson("/api/v1/production/stats?from={$today}&to={$today}")->assertOk()->json('data.totals');
        $this->assertSame([1, '4.600', '4.600'], [$stats['unattributed_lots'], $stats['unattributed_kg'], $stats['measured_kg']]);
        $this->client()->getJson("/api/v1/production/manufacturing-orders/{$this->r['of']['ulid']}")->assertJsonPath('data.waste.lots', 1);

        // Who may not read lots sees the totals of the OF, not the lots.
        $viewer = $this->makeMember($this->company, ['management_viewer' => null]);
        DB::table('role_has_permissions')->where('permission_id', DB::table('permissions')->where('name', 'lots.view')->value('id'))->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->client($viewer)->getJson("/api/v1/production/manufacturing-orders/{$this->r['of']['ulid']}")->assertOk()
            ->assertJsonPath('data.waste.lots', 1)->assertJsonPath('data.waste.can_view_lots', false)->assertJsonPath('data.waste.rows', []);
    }

    public function test_files_bring_in_lots_consumptions_and_money_and_report_every_line_they_refuse(): void
    {
        $run = fn (string $target, string $csv, array $mapping, bool $dry = false) => $this->client()->post('/api/v1/production/imports/run',
            ['target' => $target, 'file' => UploadedFile::fake()->createWithContent("{$target}.csv", $csv), 'mapping' => $mapping] + ($dry ? ['dry_run' => '1'] : []), ['Accept' => 'application/json'])->assertOk()->json('data');

        // 15. Received lots: the parent (the material) must exist; a supplier is created and said; amounts must agree.
        $lots = "Matière,Lot,Fournisseur,Date réception,Quantité,PU,Montant\nDEN,L1,,01/08/2026,100,10,\nDEN,L2,Supplier C,10/08/2026,50,,600\nABSENT,L9,,,10,1,\nDEN,L3,,,10,2,50\nDEN,L4,,,5,x,\n";
        $mapping = ['material' => 0, 'batch_number' => 1, 'supplier' => 2, 'received_on' => 3, 'batch_quantity' => 4, 'unit_price' => 5, 'total_amount' => 6];
        $preview = $this->client()->post('/api/v1/production/imports/preview', ['target' => 'received_lots', 'file' => UploadedFile::fake()->createWithContent('lots.csv', $lots)], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.lines', 5)->json('data');
        $this->assertSame($mapping, array_filter($preview['mapping'], fn ($column) => $column !== null));
        $this->assertSame('R-2026-0412', collect($preview['fields'])->firstWhere('field', 'batch_number')['example']);

        $check = $run('received_lots', $lots, $mapping, true);
        $this->assertSame([2, [4, 5, 6]], [$check['created'], array_column($check['errors'], 'line')]);
        $this->assertStringContainsString('ABSENT', $check['errors'][0]['message']);
        $this->assertSame(0, DB::table('raw_material_batches')->count());

        $done = $run('received_lots', $lots, $mapping);
        $this->assertSame([5, 2, 0, ['suppliers' => 1], 3], [$done['total'], $done['created'], $done['updated'], $done['also_created'], count($done['errors'])]);
        $rows = DB::table('raw_material_batches as b')->leftJoin('production_suppliers as s', 's.id', '=', 'b.production_supplier_id')->orderBy('b.batch_number')
            ->get(['b.batch_number', 's.name', 'b.unit_price', 'b.total_amount', 'b.currency_code', 'b.import_source'])->map(fn ($row) => array_values((array) $row))->all();
        $this->assertSame([['L1', 'Supplier A', '10.0000', '1000.000', $this->company->currency_code, 'import'], ['L2', 'Supplier C', '12.0000', '600.000', $this->company->currency_code, 'import']], $rows);
        // The same file again: nothing changes, nothing is created twice.
        $again = $run('received_lots', $lots, $mapping);
        $this->assertSame([0, 0, 2, []], [$again['created'], $again['updated'], $again['unchanged'], $again['also_created']]);
        $this->assertSame(2, DB::table('raw_material_batches')->count());

        // Consumptions: a lot that does not exist for the material is an error on its line, never another lot.
        $consumed = "OF,Matière,Consommation,Lot,Étape\nOF-1,DEN,12,L1,Coupe\nOF-1,DEN,8,L2,\nOF-1,DEN,3,,\nOF-1,DEN,5,L7,\nOF-9,DEN,5,,\n";
        $mapping = ['manufacturing_order' => 0, 'material' => 1, 'consumed_quantity' => 2, 'batch_number' => 3, 'step' => 4];
        $done = $run('consumptions', $consumed, $mapping);
        $this->assertSame([3, [5, 6]], [$done['created'], array_column($done['errors'], 'line')]);
        $this->assertStringContainsString('L7', $done['errors'][0]['message']);
        $this->assertSame(['created' => 0, 'unchanged' => 3], array_intersect_key($run('consumptions', $consumed, $mapping), ['created' => 0, 'unchanged' => 0]));
        $of = $this->client()->getJson("/api/v1/production/manufacturing-orders/{$this->r['of']['ulid']}")->json('data');
        $this->assertSame(['23.000', '3.000', '216.000'], [$of['materials'][0]['consumed'], $of['materials'][0]['unallocated'], $of['finance']['material_cost']['known']]);

        // Produced lots, expenses and invoices: children of an OF or of an order that must exist.
        $done = $run('produced_lots', "Lot,OF,Quantité,Date,Statut\nLF-A,OF-1,400,10/09/2026,livré\nLF-B,OF-1,500,12/09/2026,\nLF-C,OF-9,10,,\nLF-D,OF-1,10,,volé\n",
            ['lot_number' => 0, 'manufacturing_order' => 1, 'quantity' => 2, 'produced_on' => 3, 'status' => 4]);
        $this->assertSame([2, [4, 5]], [$done['created'], array_column($done['errors'], 'line')]);
        $this->assertSame([900, 'delivered'], [DB::table('production_orders')->where('order_number', 'OF-1')->value('quantity_produced'), DB::table('produced_lots')->where('lot_number', 'LF-A')->value('status')]);

        $expenses = "OF,Pièce,Libellé,Montant,Catégorie\nOF-1,FA-1,Délavage,100,sous-traitance\nOF-1,FA-2,Transport,40,transport\n";
        $mapping = ['manufacturing_order' => 0, 'reference' => 1, 'description' => 2, 'amount' => 3, 'category' => 4];
        $this->assertSame(2, $run('expenses', $expenses, $mapping)['created']);
        $this->assertSame(2, $run('expenses', $expenses, $mapping)['unchanged']);
        $this->assertSame('140.000', (string) DB::table('production_order_expenses')->sum('amount'));

        $done = $run('invoices', "Facture,Commande,Montant,Type,OF\nFV-1,CMD-1,2000,facture,\nAV-1,CMD-1,300,avoir,OF-1\nFV-2,CMD-9,10,,\n", ['number' => 0, 'customer_order' => 1, 'amount' => 2, 'kind' => 3, 'manufacturing_order' => 4]);
        $this->assertSame([2, [4]], [$done['created'], array_column($done['errors'], 'line')]);
        $this->client()->getJson("/api/v1/production/orders/{$this->r['order']['ulid']}")->assertOk()->assertJsonPath('data.invoiced', '1700.000')->assertJsonCount(2, 'data.invoices');
    }

    public function test_another_company_and_a_user_without_the_right_reach_nothing(): void
    {
        [$l1] = $this->receive();
        $of = $this->r['of']['ulid'];
        $this->client()->postJson("/api/v1/production/manufacturing-orders/{$of}/produced-lots", ['lot_number' => 'LF-A', 'quantity' => 900])->assertCreated();
        $this->client()->postJson("/api/v1/production/manufacturing-orders/{$of}/expenses", ['category' => 'transport', 'description' => 'Transport', 'amount' => '40'])->assertCreated();
        $detail = $this->client()->getJson("/api/v1/production/manufacturing-orders/{$of}")->json('data');

        // 16. Another company: every identifier of this one answers "not found" or "does not exist".
        $stranger = $this->makeCompany();
        $strangerAdmin = $this->makeMember($stranger, ['client_admin' => null]);
        $other = fn () => $this->client($strangerAdmin, $stranger);
        foreach (["materials/{$this->r['material']['ulid']}", "manufacturing-orders/{$of}", "orders/{$this->r['order']['ulid']}"] as $path) {
            $other()->getJson("/api/v1/production/{$path}")->assertNotFound();
        }
        $other()->putJson("/api/v1/production/batches/{$l1['ulid']}", ['batch_number' => 'X'])->assertNotFound();
        $other()->putJson("/api/v1/production/produced-lots/{$detail['produced_lots'][0]['ulid']}", ['lot_number' => 'X', 'quantity' => 1])->assertNotFound();
        $other()->deleteJson("/api/v1/production/expenses/{$detail['expenses'][0]['ulid']}")->assertNotFound();
        $other()->postJson("/api/v1/production/manufacturing-orders/{$of}/produced-lots", ['lot_number' => 'X', 'quantity' => 1])->assertNotFound();
        foreach (['received-lots', 'produced-lots'] as $list) {
            $other()->getJson("/api/v1/production/{$list}")->assertOk()->assertJsonPath('meta.total', 0);
        }
        $other()->getJson('/api/v1/production/reports/finance?from=2026-01-01&to=2026-12-31')->assertOk()->assertJsonPath('data.totals.orders', 0);
        // Its own OF cannot consume a lot of this company, nor an import name this company's material.
        $material = $other()->postJson('/api/v1/production/materials', ['reference' => 'DEN', 'name' => 'Denim', 'unit' => 'kg'])->assertCreated()->json('data');
        $article = $other()->postJson('/api/v1/production/articles', ['reference' => 'A', 'name' => 'A'])->assertCreated()->json('data');
        $theirs = $other()->postJson('/api/v1/production/manufacturing-orders', ['order_number' => 'OF-1', 'article' => $article['ulid'], 'quantity_planned' => 1])->assertCreated()->json('data');
        $other()->putJson("/api/v1/production/manufacturing-orders/{$theirs['ulid']}/consumptions", ['consumptions' => [['material' => $material['ulid'], 'batch' => $l1['ulid'], 'quantity' => '1']]])->assertUnprocessable();
        $other()->putJson("/api/v1/production/manufacturing-orders/{$theirs['ulid']}/consumptions", ['consumptions' => [['material' => $this->r['material']['ulid'], 'quantity' => '1']]])->assertUnprocessable();
        $imported = $other()->post('/api/v1/production/imports/run', ['target' => 'consumptions', 'file' => UploadedFile::fake()->createWithContent('c.csv', "OF,Matière,Qté,Lot\nOF-1,DEN,1,L1\n"),
            'mapping' => ['manufacturing_order' => 0, 'material' => 1, 'consumed_quantity' => 2, 'batch_number' => 3]], ['Accept' => 'application/json'])->assertOk()->json('data');
        $this->assertSame([0, 1], [$imported['created'], count($imported['errors'])]);
        $this->assertSame(0, DB::table('production_order_consumptions')->where('company_id', $this->company->id)->count());

        // An operator has no access to production data at all.
        $operator = $this->makeMember($this->company, ['workshop_operator' => $this->site]);
        foreach (["materials/{$this->r['material']['ulid']}", 'received-lots', 'produced-lots', "manufacturing-orders/{$of}", 'reports/finance?from=2026-01-01&to=2026-12-31'] as $path) {
            $this->client($operator)->getJson("/api/v1/production/{$path}")->assertForbidden();
        }
        $this->client($operator)->postJson("/api/v1/production/manufacturing-orders/{$of}/produced-lots", ['lot_number' => 'X', 'quantity' => 1])->assertForbidden();

        // Who follows production without the right to see money: quantities and traceability, no amount, and no way to write one.
        $viewer = $this->makeMember($this->company, ['environment_manager' => null]);
        DB::table('role_has_permissions')->where('permission_id', DB::table('permissions')->where('name', 'production.finance.view')->value('id'))
            ->where('role_id', DB::table('roles')->whereNull('company_id')->where('name', 'environment_manager')->value('id'))->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $blind = $this->client($viewer)->getJson("/api/v1/production/manufacturing-orders/{$of}")->assertOk()->json('data');
        $this->assertSame([null, [], 900], [$blind['finance'], $blind['expenses'], $blind['quantity_produced']]);
        $lot = $this->client($viewer)->getJson('/api/v1/production/received-lots?search=L1')->assertOk()->json('data.0');
        $this->assertSame(['Supplier A', '100.000', null, null, null, null], [$lot['supplier']['name'], $lot['quantity'], $lot['unit_price'], $lot['total_amount'], $lot['unit_cost'], $lot['purchase_value']]);
        $this->client($viewer)->getJson("/api/v1/production/orders/{$this->r['order']['ulid']}")->assertOk()->assertJsonPath('data.order_value', null)->assertJsonPath('data.lines.0.unit_price', null);
        $this->client($viewer)->getJson('/api/v1/production/reports/finance?from=2026-01-01&to=2026-12-31')->assertForbidden();
        $this->client($viewer)->postJson("/api/v1/production/manufacturing-orders/{$of}/expenses", ['category' => 'transport', 'description' => 'x', 'amount' => '1'])->assertForbidden();
        $this->client($viewer)->postJson("/api/v1/production/orders/{$this->r['order']['ulid']}/invoices", ['kind' => 'invoice', 'number' => 'X', 'amount' => '1'])->assertForbidden();
        // He corrects a lot and an order: the amounts they had stay.
        $this->client($viewer)->putJson("/api/v1/production/batches/{$l1['ulid']}", ['batch_number' => 'L1', 'quantity' => '100', 'unit_price' => '1'])->assertOk();
        $this->client($viewer)->putJson("/api/v1/production/orders/{$this->r['order']['ulid']}", ['order_number' => 'CMD-1', 'client' => $this->r['client']['ulid'],
            'lines' => [['article' => $this->r['article']['ulid'], 'quantity' => 1000, 'unit_price' => '1']]])->assertOk();
        $this->assertSame(['10.0000', '5.0000', '500.000'], [DB::table('raw_material_batches')->where('batch_number', 'L1')->value('unit_price'),
            DB::table('customer_order_lines')->where('company_id', $this->company->id)->value('unit_price'), DB::table('customer_orders')->where('company_id', $this->company->id)->value('discount_amount')]);
        $this->client($viewer)->post('/api/v1/production/imports/run', ['target' => 'invoices', 'file' => UploadedFile::fake()->createWithContent('i.csv', "N,C,M\nFV-1,CMD-1,10\n"),
            'mapping' => ['number' => 0, 'customer_order' => 1, 'amount' => 2]], ['Accept' => 'application/json'])->assertForbidden();
    }
}
