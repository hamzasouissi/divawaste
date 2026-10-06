<?php

namespace Tests\Feature\Sites;

use App\Modules\Catalog\Models\WasteType;
use App\Modules\Sites\Models\Site;
use App\Modules\Sites\Models\Zone;
use App\Modules\Tenancy\Models\Company;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class StockThresholdTest extends TestCase
{
    use InteractsWithTenants;
    use RefreshDatabase;

    private Company $company;

    private Site $sfax;

    private Site $monastir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([ReferenceDataSeeder::class, AuthorizationSeeder::class]);
        $this->company = $this->makeCompany();
        $this->sfax = $this->makeSite($this->company, 'SFX');
        $this->monastir = $this->makeSite($this->company, 'MON');
    }

    public function test_environment_manager_sets_thresholds_on_own_site_only(): void
    {
        $token = $this->tokenFor($this->makeMember($this->company, ['environment_manager' => $this->sfax]), $this->company);
        $zone = $this->zone($this->sfax);
        $type = $this->wasteType();

        $this->withToken($token)
            ->postJson('/api/v1/stock-thresholds', ['site' => $this->sfax->ulid, 'zone' => $zone->ulid, 'waste_type' => $type->ulid, 'max_quantity_kg' => '2000'])
            ->assertCreated()
            ->assertJsonPath('data.zone', $zone->ulid)
            ->assertJsonPath('data.warning_pct', 90)
            ->assertJsonPath('data.alert_level', null);

        $this->withToken($token)
            ->postJson('/api/v1/stock-thresholds', ['site' => $this->monastir->ulid, 'max_quantity_kg' => '2000'])
            ->assertForbidden();
    }

    public function test_zone_must_belong_to_the_site(): void
    {
        $token = $this->tokenFor($this->makeMember($this->company, ['client_admin' => null]), $this->company);

        $this->withToken($token)
            ->postJson('/api/v1/stock-thresholds', ['site' => $this->sfax->ulid, 'zone' => $this->zone($this->monastir)->ulid, 'max_quantity_kg' => '10'])
            ->assertStatus(422)->assertJsonStructure(['errors' => ['zone']]);
    }

    public function test_one_threshold_per_site_zone_type_subject(): void
    {
        $token = $this->tokenFor($this->makeMember($this->company, ['client_admin' => null]), $this->company);
        $payload = ['site' => $this->sfax->ulid, 'max_quantity_kg' => '500'];

        $this->withToken($token)->postJson('/api/v1/stock-thresholds', $payload)->assertCreated();
        $this->withToken($token)->postJson('/api/v1/stock-thresholds', $payload)->assertStatus(409)->assertJsonPath('code', 'THRESHOLD_EXISTS');
    }

    public function test_references_of_another_company_are_rejected(): void
    {
        $other = $this->makeCompany();
        $foreignSite = $this->makeSite($other, 'OTH');
        $token = $this->tokenFor($this->makeMember($this->company, ['client_admin' => null]), $this->company);

        $this->withToken($token)
            ->postJson('/api/v1/stock-thresholds', ['site' => $foreignSite->ulid, 'max_quantity_kg' => '10'])
            ->assertStatus(422)->assertJsonStructure(['errors' => ['site']]);
    }

    public function test_database_enforces_zone_in_site(): void
    {
        $this->expectException(QueryException::class);

        DB::table('stock_thresholds')->insert([
            'ulid' => '01JC2Z8M4V7H3K9P2RQW5X6Y8Z', 'company_id' => $this->company->id, 'site_id' => $this->sfax->id,
            'zone_id' => $this->zone($this->monastir)->id, 'max_quantity_kg' => '1',
        ]);
    }

    public function test_listing_is_limited_to_sites_the_user_can_view(): void
    {
        $admin = $this->makeMember($this->company, ['client_admin' => null]);
        foreach ([$this->sfax, $this->monastir] as $site) {
            $this->withToken($this->tokenFor($admin, $this->company))
                ->postJson('/api/v1/stock-thresholds', ['site' => $site->ulid, 'max_quantity_kg' => '100'])->assertCreated();
        }

        $operator = $this->makeMember($this->company, ['workshop_operator' => $this->monastir]);
        $this->withToken($this->tokenFor($operator, $this->company))
            ->getJson('/api/v1/stock-thresholds')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.site', $this->monastir->ulid);
    }

    private function zone(Site $site): Zone
    {
        return $this->tenant()->runAs($this->company, fn () => Zone::query()->create([
            'site_id' => $site->id, 'zone_type_id' => DB::table('zone_types')->where('code', 'waste_storage')->value('id'),
            'code' => 'Z'.$site->code, 'name' => 'Aire',
        ]));
    }

    private function wasteType(): WasteType
    {
        return $this->tenant()->runAs($this->company, fn () => WasteType::query()->create([
            'code' => 'CHUTES', 'name' => 'Chutes', 'waste_family_id' => DB::table('waste_families')->value('id'),
            'default_unit_id' => DB::table('units')->where('code', 'kg')->value('id'), 'activated_at' => now(),
        ]));
    }
}
