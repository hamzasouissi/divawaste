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
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Scrap reasons (motifs de rebut): a list of the company that lots name, so scraps are counted, weighed and valued per
 * reason. The fabric prices a kilo at 11 TND (see LotCostTest).
 */
class ScrapReasonsTest extends TestCase
{
    use InteractsWithTenants;
    use RefreshDatabase;

    private Company $company;

    private Site $site;

    private Site $other;

    private string $admin;

    private string $zone;

    private string $otherZone;

    private string $type;

    private RawMaterial $fabric;

    private RawMaterialBatch $batch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([ReferenceDataSeeder::class, AuthorizationSeeder::class]);
        $this->company = $this->makeCompany();
        $this->site = $this->makeSite($this->company, 'SFX');
        $this->other = $this->makeSite($this->company, 'TUN');
        $this->admin = $this->tokenFor($this->makeMember($this->company, ['client_admin' => null]), $this->company);
        [$this->zone, $this->otherZone, $this->type, $this->fabric, $this->batch] = $this->tenant()->runAs($this->company, function () {
            $fabric = RawMaterial::query()->create(['reference' => '10044', 'name' => 'Néoprène 3 mm', 'unit_id' => DB::table('units')->where('code', 'm')->value('id'),
                'width_cm' => '150', 'grammage_gsm' => '200']);
            $zone = fn (Site $site) => Zone::query()->create(['code' => 'AIRE', 'site_id' => $site->id, 'zone_type_id' => DB::table('zone_types')->value('id'), 'name' => 'Aire'])->ulid;

            return [
                $zone($this->site), $zone($this->other),
                WasteType::query()->create(['code' => 'CHUTES', 'name' => 'Chutes', 'waste_family_id' => DB::table('waste_families')->value('id'),
                    'default_unit_id' => DB::table('units')->where('code', 'kg')->value('id'), 'activated_at' => now()])->ulid,
                $fabric,
                RawMaterialBatch::query()->create(['raw_material_id' => $fabric->id, 'batch_number' => 'R-1', 'quantity' => '100', 'received_on' => '2026-01-10',
                    'total_amount' => '300', 'delivery_cost' => '30', 'currency_code' => 'TND']),
            ];
        });
    }

    /** @return array<string, mixed> */
    private function reason(array $values, ?string $token = null): array
    {
        $this->as();

        return $this->withToken($token ?? $this->admin)->postJson('/api/v1/scrap-reasons', $values)->assertCreated()->json('data');
    }

    /**
     * The auth guard keeps the user of the previous request: after a request with another company's token, the next
     * one would still run as that user. Called before switching tokens.
     */
    private function as(): void
    {
        app('auth')->forgetGuards();
    }

    /** @return array<string, mixed> */
    private function lot(string $weight, array $more = [], bool $other = false): array
    {
        return $this->withToken($this->admin)->postJson('/api/v1/lots', ['site' => ($other ? $this->other : $this->site)->ulid, 'zone' => $other ? $this->otherZone : $this->zone,
            'waste_type' => $this->type, 'net_weight_kg' => $weight, 'raw_material' => $this->fabric->ulid, 'raw_material_batch' => $this->batch->ulid] + $more)->assertCreated()->json('data');
    }

    public function test_the_company_keeps_its_list_of_reasons(): void
    {
        $holes = $this->reason(['code' => ' ue24 ', 'label' => ' Trous dans le tissu ', 'category' => 'material', 'color' => '#e5484d']);
        $this->assertSame(['UE24', 'Trous dans le tissu', 'material', '#E5484D', true, 0], [$holes['code'], $holes['label'], $holes['category'], $holes['color'], $holes['is_active'], $holes['lots']]);
        $this->reason(['code' => 'UE25', 'label' => 'Couture décalée', 'category' => 'manpower']);

        // Codes are unique in the company, not across companies.
        $this->withToken($this->admin)->postJson('/api/v1/scrap-reasons', ['code' => 'UE24', 'label' => 'Bis'])->assertStatus(422)->assertJsonValidationErrors('code');
        $this->withToken($this->admin)->postJson('/api/v1/scrap-reasons', ['code' => 'UE26', 'label' => 'X', 'category' => 'luck', 'color' => 'red'])->assertJsonValidationErrors(['category', 'color']);
        $elsewhere = $this->makeCompany();
        $this->reason(['code' => 'UE24', 'label' => 'Ailleurs'], $this->tokenFor($this->makeMember($elsewhere, ['client_admin' => null]), $elsewhere));

        $this->as();
        $list = $this->withToken($this->admin)->getJson('/api/v1/scrap-reasons')->assertOk()->json('data');
        $this->assertSame(['UE24', 'UE25'], array_column($list, 'code'));

        // Put in the order the workshop wants, then renamed and stopped.
        $ordered = $this->withToken($this->admin)->putJson('/api/v1/scrap-reasons/order', ['reasons' => array_reverse(array_column($list, 'ulid'))])->assertOk()->json('data');
        $this->assertSame(['UE25', 'UE24'], array_column($ordered, 'code'));
        $this->withToken($this->admin)->putJson("/api/v1/scrap-reasons/{$holes['ulid']}", ['label' => 'Trous', 'is_active' => false])->assertOk()->assertJsonPath('data.label', 'Trous');
        $this->assertSame(['UE25'], array_column($this->withToken($this->admin)->getJson('/api/v1/scrap-reasons')->json('data'), 'code'));
        $this->assertCount(2, $this->withToken($this->admin)->getJson('/api/v1/scrap-reasons?with_inactive=1')->json('data'));
    }

    public function test_readers_read_the_list_and_only_catalogue_keepers_change_it(): void
    {
        $holes = $this->reason(['code' => 'UE24', 'label' => 'Trous']);
        $auditor = $this->tokenFor($this->makeMember($this->company, ['auditor' => null]), $this->company);

        $this->withToken($auditor)->getJson('/api/v1/scrap-reasons')->assertOk()->assertJsonCount(1, 'data');
        $this->withToken($auditor)->postJson('/api/v1/scrap-reasons', ['code' => 'X', 'label' => 'X'])->assertForbidden();
        $this->withToken($auditor)->putJson("/api/v1/scrap-reasons/{$holes['ulid']}", ['label' => 'X'])->assertForbidden();
        $this->withToken($auditor)->deleteJson("/api/v1/scrap-reasons/{$holes['ulid']}")->assertForbidden();
        // Another company's reason does not exist here.
        $elsewhere = $this->makeCompany();
        $theirs = $this->reason(['code' => 'Z1', 'label' => 'Z'], $this->tokenFor($this->makeMember($elsewhere, ['client_admin' => null]), $elsewhere));
        $this->as();
        $this->withToken($this->admin)->putJson("/api/v1/scrap-reasons/{$theirs['ulid']}", ['label' => 'mine'])->assertNotFound();
    }

    public function test_a_lot_names_its_reason_and_keeps_a_note_of_its_own(): void
    {
        $holes = $this->reason(['code' => 'UE24', 'label' => 'Trous dans le tissu', 'color' => '#E5484D']);
        $seam = $this->reason(['code' => 'UE25', 'label' => 'Couture décalée']);

        $lot = $this->lot('2.5', ['scrap_reason' => $holes['ulid'], 'scrap_note' => ' Au niveau de la coupe ']);
        $this->assertSame(['UE24', 'Trous dans le tissu', '#E5484D', 'Au niveau de la coupe'],
            [$lot['scrap_reason']['code'], $lot['scrap_reason']['label'], $lot['scrap_reason']['color'], $lot['scrap_note']]);

        $updated = $this->withToken($this->admin)->putJson("/api/v1/lots/{$lot['ulid']}/scrap", ['scrap_reason' => $seam['ulid'], 'scrap_note' => null])->assertOk()->json('data');
        $this->assertSame(['UE25', null], [$updated['scrap_reason']['code'], $updated['scrap_note']]);

        // A reason no longer used cannot be given to a lot, but stays on the lot that has it.
        $this->withToken($this->admin)->putJson("/api/v1/scrap-reasons/{$seam['ulid']}", ['is_active' => false])->assertOk();
        $this->withToken($this->admin)->postJson('/api/v1/lots', ['site' => $this->site->ulid, 'zone' => $this->zone, 'waste_type' => $this->type, 'net_weight_kg' => '1',
            'scrap_reason' => $seam['ulid']])->assertStatus(422)->assertJsonValidationErrors('scrap_reason');
        $this->withToken($this->admin)->putJson("/api/v1/lots/{$lot['ulid']}/scrap", ['scrap_reason' => $seam['ulid'], 'scrap_note' => 'Toujours'])->assertOk();
        $this->withToken($this->admin)->putJson("/api/v1/lots/{$lot['ulid']}/scrap", ['scrap_reason' => '01ARZ3NDEKTSV4RRFFQ69G5FAV'])->assertStatus(422);

        // Lots are found by their reason.
        $this->lot('1', ['scrap_reason' => $holes['ulid']]);
        $found = $this->withToken($this->admin)->getJson('/api/v1/lots?filter[scrap_reason]='.$holes['ulid'])->assertOk()->json('data');
        $this->assertCount(1, $found);
    }

    public function test_a_split_keeps_the_reason(): void
    {
        $holes = $this->reason(['code' => 'UE24', 'label' => 'Trous']);
        $lot = $this->lot('10', ['scrap_reason' => $holes['ulid']]);

        $children = $this->withToken($this->admin)->postJson("/api/v1/lots/{$lot['ulid']}/split", ['outputs' => [['net_weight_kg' => '6'], ['net_weight_kg' => '4']]])->assertCreated()->json('data');

        $this->assertSame(['UE24', 'UE24'], array_map(fn (array $child) => $child['scrap_reason']['code'] ?? null, $children));

        // Still one scrap of 10 kg: the lot split stays, closed, beside its two parts, and is counted once.
        $row = collect($this->withToken($this->admin)->getJson('/api/v1/scrap-reasons')->json('data'))->firstWhere('code', 'UE24');
        $this->assertSame([1, '10.000', ['amount' => '110.000', 'currency' => 'TND']], [$row['lots'], $row['weight_kg'], $row['material_value']]);
    }

    public function test_a_grouping_keeps_one_reason_and_counts_each_scrap_once(): void
    {
        $holes = $this->reason(['code' => 'UE24', 'label' => 'Trous']);
        $seam = $this->reason(['code' => 'UE25', 'label' => 'Couture']);
        $a = $this->lot('2', ['scrap_reason' => $holes['ulid']]);
        $b = $this->lot('3', ['scrap_reason' => $holes['ulid']]);
        $c = $this->lot('4', ['scrap_reason' => $seam['ulid']]);

        $same = $this->withToken($this->admin)->postJson('/api/v1/lots/group', ['lots' => [$a['ulid'], $b['ulid']], 'zone' => $this->zone])->assertCreated()->json('data');
        $this->assertSame('UE24', $same['scrap_reason']['code']);
        $mixed = $this->withToken($this->admin)->postJson('/api/v1/lots/group', ['lots' => [$same['ulid'], $c['ulid']], 'zone' => $this->zone])->assertCreated()->json('data');
        $this->assertNull($mixed['scrap_reason']);

        $rows = collect($this->withToken($this->admin)->getJson('/api/v1/scrap-reasons')->json('data'))->keyBy('code');
        $this->assertSame([[2, '5.000'], [1, '4.000']], [[$rows['UE24']['lots'], $rows['UE24']['weight_kg']], [$rows['UE25']['lots'], $rows['UE25']['weight_kg']]]);
    }

    public function test_each_reason_says_what_its_lots_weigh_and_cost_inside_the_sites_one_may_read(): void
    {
        $holes = $this->reason(['code' => 'UE24', 'label' => 'Trous']);
        $seam = $this->reason(['code' => 'UE25', 'label' => 'Couture']);
        $this->lot('2.5', ['scrap_reason' => $holes['ulid']]);
        $this->lot('1.5', ['scrap_reason' => $holes['ulid']]);
        $this->lot('3', ['scrap_reason' => $seam['ulid']], other: true);
        $this->lot('9');  // no reason: counted nowhere

        $all = collect($this->withToken($this->admin)->getJson('/api/v1/scrap-reasons')->json('data'))->keyBy('code');
        $this->assertSame([2, '4.000', ['amount' => '44.000', 'currency' => 'TND']], [$all['UE24']['lots'], $all['UE24']['weight_kg'], $all['UE24']['material_value']]);
        $this->assertSame([1, '3.000'], [$all['UE25']['lots'], $all['UE25']['weight_kg']]);

        // Someone who reads only the lots of one site, and no production costs.
        $local = $this->tokenFor($this->makeMember($this->company, ['auditor' => $this->site]), $this->company);
        $mine = collect($this->withToken($local)->getJson('/api/v1/scrap-reasons')->json('data'))->keyBy('code');
        $this->assertSame([2, 0, null], [$mine['UE24']['lots'], $mine['UE25']['lots'], $mine['UE24']['material_value']]);

        // A period counts only the lots generated in it.
        $this->assertSame(0, collect($this->withToken($this->admin)->getJson('/api/v1/scrap-reasons?to=2020-01-01')->json('data'))->sum('lots'));
    }

    public function test_a_reason_in_use_is_deactivated_not_deleted(): void
    {
        $holes = $this->reason(['code' => 'UE24', 'label' => 'Trous']);
        $unused = $this->reason(['code' => 'UE99', 'label' => 'Jamais']);
        $this->lot('1', ['scrap_reason' => $holes['ulid']]);

        $this->withToken($this->admin)->deleteJson("/api/v1/scrap-reasons/{$holes['ulid']}")->assertStatus(409)->assertJsonPath('code', 'SCRAP_REASON_IN_USE');
        $this->withToken($this->admin)->deleteJson("/api/v1/scrap-reasons/{$unused['ulid']}")->assertNoContent();
        $this->assertSame(['UE24'], DB::table('scrap_reasons')->pluck('code')->all());
    }
}
