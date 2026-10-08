<?php

namespace Tests\Feature\Sites;

use App\Modules\Catalog\Models\WasteType;
use App\Modules\Sites\Models\Site;
use App\Modules\Tenancy\Models\Company;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * A zone is organised freely: no mandatory type, a capacity counted in lots per packaging, and optional lists of
 * the waste types and colours it is meant for. A lot that does not match is reported, never refused.
 */
class ZoneOrganisationTest extends TestCase
{
    use InteractsWithTenants;
    use RefreshDatabase;

    private Company $company;

    private Site $site;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([ReferenceDataSeeder::class, AuthorizationSeeder::class]);
        $this->company = $this->makeCompany();
        $this->site = $this->makeSite($this->company, 'SFX');
        $this->token = $this->tokenFor($this->makeMember($this->company, ['client_admin' => null]), $this->company);
    }

    public function test_a_zone_needs_no_type_and_keeps_its_capacities_and_accepted_lists(): void
    {
        $chutes = $this->wasteType($this->company, 'CHUTES');

        $zone = $this->withToken($this->token)->postJson("/api/v1/sites/{$this->site->ulid}/zones", [
            'code' => 'B1', 'name' => 'Balles blanches',
            'capacities' => [['packaging_type' => 'bale', 'max_count' => 40], ['packaging_type' => 'bag', 'max_count' => 100]],
            'accepted_waste_types' => [$chutes->ulid], 'accepted_colors' => ['white', 'ecru'],
        ])->assertCreated()
            ->assertJsonPath('data.zone_type', null)
            ->assertJsonPath('data.capacities.0', ['packaging_type' => 'bale', 'max_count' => 40])
            ->assertJsonPath('data.accepted_waste_types', [$chutes->ulid])
            ->assertJsonPath('data.accepted_colors', ['white', 'ecru'])
            ->assertJsonPath('data.fill.pct', 0)
            ->json('data');

        // A list that is sent replaces the previous one; a list left out is kept.
        $this->withToken($this->token)->putJson("/api/v1/zones/{$zone['ulid']}", [
            'code' => 'B1', 'name' => 'Balles', 'capacities' => [['packaging_type' => 'bale', 'max_count' => 10]], 'accepted_colors' => [],
        ])->assertOk()
            ->assertJsonPath('data.capacities', [['packaging_type' => 'bale', 'max_count' => 10]])
            ->assertJsonPath('data.accepted_waste_types', [$chutes->ulid])
            ->assertJsonPath('data.accepted_colors', []);
    }

    public function test_lists_refuse_what_the_company_cannot_use(): void
    {
        $foreign = $this->wasteType($this->makeCompany(), 'AUTRE');

        $this->withToken($this->token)->postJson("/api/v1/sites/{$this->site->ulid}/zones", [
            'code' => 'B1', 'name' => 'Zone', 'accepted_waste_types' => [$foreign->ulid], 'accepted_colors' => ['no_such_colour'],
            'capacities' => [['packaging_type' => 'bale', 'max_count' => 0], ['packaging_type' => 'bale', 'max_count' => 5]],
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['accepted_waste_types.0', 'accepted_colors.0', 'capacities.0.max_count', 'capacities.1.packaging_type']]);

        $this->assertSame(0, DB::table('zones')->where('code', 'B1')->count());
    }

    public function test_fill_adds_the_share_of_each_packaging_and_reports_what_does_not_match(): void
    {
        $chutes = $this->wasteType($this->company, 'CHUTES');
        $fils = $this->wasteType($this->company, 'FILS');
        $zone = $this->withToken($this->token)->postJson("/api/v1/sites/{$this->site->ulid}/zones", [
            'code' => 'B1', 'name' => 'Balles', 'capacity_kg' => '1000', 'capacity_alert_pct' => 70,
            'capacities' => [['packaging_type' => 'bale', 'max_count' => 4], ['packaging_type' => 'bag', 'max_count' => 10]],
            'accepted_waste_types' => [$chutes->ulid], 'accepted_colors' => ['white'],
        ])->assertCreated()->json('data.ulid');

        $lot = fn (WasteType $type, ?string $packaging, ?string $color) => $this->withToken($this->token)->postJson('/api/v1/lots', array_filter([
            'site' => $this->site->ulid, 'zone' => $zone, 'waste_type' => $type->ulid, 'net_weight_kg' => '100', 'packaging_type' => $packaging, 'color_family' => $color,
        ]))->assertCreated();

        $lot($chutes, 'bale', 'white');
        $lot($chutes, 'bale', null);     // no colour stated: counted, not called misplaced
        $lot($chutes, 'bag', 'black');   // wrong colour
        $lot($fils, 'bag', 'white');     // wrong waste type
        $lot($chutes, 'drum', 'white');  // a packaging the zone says nothing about
        $lot($chutes, null, 'white');    // no packaging at all

        // What a request has read is kept for that request only; the test runs several of them in one process.
        $this->app->forgetScopedInstances();

        // 2 bales of 4 (50 %) + 2 bags of 10 (20 %) = 70 %; by weight 600 kg of 1000 = 60 %. The higher one counts.
        $fill = collect($this->withToken($this->token)->getJson("/api/v1/sites/{$this->site->ulid}")->assertOk()->json('data.zones'))->firstWhere('ulid', $zone)['fill'];
        $this->assertEquals(70, $fill['pct']);
        $this->assertEquals(70, $fill['count_pct']);
        $this->assertEquals(60, $fill['weight_pct']);
        $this->assertSame('warning', $fill['alert_level']);
        $this->assertSame(6, $fill['lots']);
        $this->assertSame(2, $fill['uncounted']);
        $this->assertSame(2, $fill['misplaced']);
        $this->assertSame(['bale' => 1, 'bag' => 3], $fill['remaining']['counts']);
        $this->assertSame('400.000', $fill['remaining']['kg']);

        // The stock page shows the same figures, whatever the filter applied to its rows.
        $this->withToken($this->token)->getJson('/api/v1/stock?filter[waste_type]='.$fils->ulid)->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.zone_quantity_kg', '600.000')
            ->assertJsonPath('data.0.zone_alert_level', 'warning')
            ->assertJsonPath('data.0.zone_fill.misplaced', 2);
    }

    public function test_a_company_colour_carries_a_pantone_reference(): void
    {
        $code = $this->withToken($this->token)->postJson('/api/v1/colors', ['name' => 'Bleu client', 'hex_color' => '#0f4c81', 'pantone_reference' => ' 19-4052 TCX '])
            ->assertCreated()->assertJsonPath('data.pantone_reference', '19-4052 TCX')->json('data.code');

        $reference = collect($this->withToken($this->token)->getJson('/api/v1/reference')->assertOk()->json('data.color_families'))->firstWhere('code', $code);
        $this->assertSame('19-4052 TCX', $reference['pantone_reference']);

        $this->withToken($this->token)->putJson("/api/v1/colors/{$code}", ['name' => 'Bleu client'])->assertOk()->assertJsonPath('data.pantone_reference', null);
    }

    private function wasteType(Company $company, string $code): WasteType
    {
        return $this->tenant()->runAs($company, fn () => WasteType::query()->create([
            'code' => $code, 'name' => ucfirst(strtolower($code)), 'waste_family_id' => DB::table('waste_families')->value('id'),
            'default_unit_id' => DB::table('units')->where('code', 'kg')->value('id'), 'activated_at' => now(),
        ]));
    }
}
