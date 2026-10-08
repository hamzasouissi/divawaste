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
 * Statistics of the waste by its origin in production: measured when the lot names its manufacturing order,
 * estimated when it only says its stage, never mixed silently.
 */
class ProductionStatsTest extends TestCase
{
    use InteractsWithTenants;

    private Company $company;

    private Site $site;

    private User $admin;

    private WasteType $type;

    private Zone $cutting;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([ReferenceDataSeeder::class, AuthorizationSeeder::class]);
        $this->company = $this->makeCompany();
        $this->site = $this->makeSite($this->company, 'NAB');
        $this->admin = $this->makeMember($this->company, ['client_admin' => null]);
        [$this->type, $this->cutting] = $this->tenant()->runAs($this->company, fn () => [
            WasteType::query()->create(['code' => 'CHUTES', 'name' => 'Chutes', 'waste_family_id' => DB::table('waste_families')->value('id'),
                'default_unit_id' => DB::table('units')->where('code', 'kg')->value('id'), 'activated_at' => now()]),
            Zone::query()->create(['site_id' => $this->site->id, 'zone_type_id' => DB::table('zone_types')->where('code', 'cutting')->value('id'), 'code' => 'COUPE', 'name' => 'Coupe']),
        ]);
    }

    public function test_waste_is_read_by_order_client_material_and_stage_measured_and_estimated_apart(): void
    {
        $levant = $this->make('clients', ['code' => 'LEV', 'name' => 'Levant Denim']);
        $nordic = $this->make('clients', ['code' => 'NOR', 'name' => 'Nordic Wear']);
        $mills = $this->make('suppliers', ['code' => 'MIL', 'name' => 'Denim Mills']);
        // 150 cm wide, 400 g/m²: one metre weighs 0.6 kg.
        $denim = $this->make('materials', ['reference' => 'DEN', 'name' => 'Denim', 'supplier' => $mills['ulid'], 'unit' => 'm', 'grammage_gsm' => '400', 'width_cm' => '150']);
        $jean = $this->make('articles', ['reference' => 'JEAN', 'name' => 'Jean', 'client' => $levant['ulid'], 'materials' => [['material' => $denim['ulid'], 'quantity_per_piece' => '1.5']]]);
        $shirt = $this->make('articles', ['reference' => 'CHEM', 'name' => 'Chemise', 'client' => $nordic['ulid']]);
        $stage = [['step' => 'cutting', 'site' => $this->site->ulid]];
        // Two orders at the cutting stage of the site today: 300 and 100 pieces done.
        $ofJean = $this->make('manufacturing-orders', ['order_number' => 'OF-1', 'article' => $jean['ulid'], 'quantity_planned' => 300, 'quantity_produced' => 300, 'status' => 'in_progress',
            'started_on' => now()->subDays(3)->toDateString(), 'steps' => [$stage[0] + ['pieces_done' => 300]], 'consumptions' => [['material' => $denim['ulid'], 'quantity' => '500']]]);
        $this->make('manufacturing-orders', ['order_number' => 'OF-2', 'article' => $shirt['ulid'], 'quantity_planned' => 100, 'quantity_produced' => 100, 'status' => 'in_progress',
            'started_on' => now()->subDays(3)->toDateString(), 'steps' => [$stage[0] + ['pieces_done' => 100]]]);
        // An order that starts tomorrow is at no stage yet.
        $this->make('manufacturing-orders', ['order_number' => 'OF-3', 'article' => $shirt['ulid'], 'quantity_planned' => 999, 'steps' => $stage]);

        $lot = fn (string $kg, ?string $of, ?Zone $zone = null) => $this->client()->postJson('/api/v1/lots', array_filter(['site' => $this->site->ulid, 'zone' => ($zone ?? $this->cutting)->ulid,
            'waste_type' => $this->type->ulid, 'net_weight_kg' => $kg, 'production_order' => $of]))->assertCreated()->json('data');
        $lot('30', $ofJean['ulid']);                 // measured: OF-1
        $lot('40', null);                            // only its stage: shared 300/100 → 30 kg and 10 kg
        $storage = $this->tenant()->runAs($this->company, fn () => Zone::query()->where('site_id', $this->site->id)->where('id', '!=', $this->cutting->id)->firstOrFail());
        $lot('7', null, $storage);                   // says nothing: not attributed

        $stats = $this->client()->getJson('/api/v1/production/stats?from='.now()->subDays(5)->toDateString().'&to='.now()->toDateString())->assertOk()->json('data');

        $this->assertSame(['77.000', '30.000', '40.000', '7.000', 1], [$stats['totals']['waste_kg'], $stats['totals']['measured_kg'], $stats['totals']['estimated_kg'],
            $stats['totals']['unattributed_kg'], $stats['totals']['unattributed_lots']]);

        // OF-1: 30 measured + 30 estimated = 60 kg for 300 pieces = 200 g per piece; it consumed 500 m = 300 kg, so 20 % became waste.
        $byOrder = collect($stats['by_order'])->keyBy('label');
        $this->assertEquals(['30.000', '30.000', '60.000', 300, 200, '300.000', 20], [$byOrder['OF-1']['measured_kg'], $byOrder['OF-1']['estimated_kg'], $byOrder['OF-1']['total_kg'],
            $byOrder['OF-1']['pieces'], $byOrder['OF-1']['g_per_piece'], $byOrder['OF-1']['consumed_kg'], $byOrder['OF-1']['waste_rate_pct']]);
        $this->assertEquals(['0', '10.000', 100, null], [$byOrder['OF-2']['measured_kg'], $byOrder['OF-2']['estimated_kg'], $byOrder['OF-2']['g_per_piece'], $byOrder['OF-2']['waste_rate_pct']]);
        $this->assertArrayNotHasKey('OF-3', $byOrder->all());

        $this->assertSame(['Levant Denim' => '60.000', 'Nordic Wear' => '10.000'], collect($stats['by_client'])->pluck('total_kg', 'label')->all());
        $this->assertSame(['JEAN' => '60.000', 'CHEM' => '10.000'], collect($stats['by_article'])->pluck('total_kg', 'label')->all());
        // The material is known for the lot attached to OF-1 and for the share of the stage-only lot given to it.
        $this->assertSame(['DEN' => '60.000'], collect($stats['by_material'])->pluck('total_kg', 'label')->all());
        $this->assertSame(['Denim Mills' => '60.000'], collect($stats['by_supplier'])->pluck('total_kg', 'label')->all());
        $this->assertSame(['cutting' => '70.000'], collect($stats['by_step'])->pluck('total_kg', 'key')->all());

        // The same figures as a spreadsheet; the list of the lots behind a figure.
        $this->client()->get('/api/v1/production/stats/export?from='.now()->subDays(5)->toDateString().'&to='.now()->toDateString())->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->client()->getJson('/api/v1/lots?filter[raw_material]='.$denim['ulid'])->assertOk()->assertJsonCount(1, 'data');

        // Reserved to those who may read production statistics; another company's figures are its own.
        $operator = $this->tokenFor($this->makeMember($this->company, ['workshop_operator' => $this->site]), $this->company);
        $this->withToken($operator)->getJson('/api/v1/production/stats?from=2026-01-01&to=2026-12-31')->assertForbidden();
        $other = $this->makeCompany();
        $stranger = $this->tokenFor($this->makeMember($other, ['client_admin' => null]), $other);
        $this->withToken($stranger)->getJson('/api/v1/production/stats?from=2020-01-01&to=2030-12-31')->assertOk()->assertJsonPath('data.totals.waste_kg', '0.000')->assertJsonCount(0, 'data.by_order');
    }

    public function test_pieces_produced_follow_the_finished_orders_without_overwriting_what_was_typed(): void
    {
        $article = $this->make('articles', ['reference' => 'JEAN', 'name' => 'Jean']);
        $finished = now()->subMonth()->startOfMonth()->addDays(9);
        $payload = ['order_number' => 'OF-1', 'article' => $article['ulid'], 'quantity_planned' => 500, 'quantity_produced' => 480, 'status' => 'completed',
            'started_on' => $finished->copy()->subDays(8)->toDateString(), 'finished_on' => $finished->toDateString(), 'steps' => [['step' => 'sewing', 'site' => $this->site->ulid]]];
        $of = $this->make('manufacturing-orders', $payload);

        $month = $finished->copy()->startOfMonth()->toDateString();
        // [pieces, source] of the month, or null when the month has no figure.
        $row = function () use ($month) {
            $found = DB::table('production_volumes')->where('site_id', $this->site->id)->whereDate('period_month', $month)->first(['pieces_produced', 'source']);

            return $found === null ? null : [(int) $found->pieces_produced, $found->source];
        };
        $this->assertSame([480, 'production_orders'], $row());

        // Corrected on the order: the month follows. Reopened: the month no longer counts it.
        $this->client()->putJson("/api/v1/production/manufacturing-orders/{$of['ulid']}", ['quantity_produced' => 470] + $payload)->assertOk();
        $this->assertSame([470, 'production_orders'], $row());
        $this->client()->putJson("/api/v1/production/manufacturing-orders/{$of['ulid']}", ['status' => 'in_progress', 'finished_on' => null] + $payload)->assertOk();
        $this->assertNull($row());

        // A month typed by hand is left alone.
        $this->client()->putJson('/api/v1/production-volumes', ['site' => $this->site->ulid, 'month' => $finished->format('Y-m'), 'pieces_produced' => 1234])->assertNoContent();
        $this->client()->putJson("/api/v1/production/manufacturing-orders/{$of['ulid']}", $payload)->assertOk();
        $this->assertSame([1234, 'manual'], $row());
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function make(string $path, array $body): array
    {
        return $this->client()->postJson("/api/v1/production/{$path}", $body)->assertCreated()->json('data');
    }

    private function client(): static
    {
        return $this->withToken($this->tokenFor($this->admin, $this->company));
    }
}
