<?php

namespace Tests\Feature\Sites;

use App\Modules\Sites\Models\Zone;
use App\Modules\Tenancy\Models\Company;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class SiteApiTest extends TestCase
{
    use InteractsWithTenants;
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([ReferenceDataSeeder::class, AuthorizationSeeder::class]);
        $this->company = $this->makeCompany();
    }

    public function test_client_admin_creates_a_site(): void
    {
        $admin = $this->makeMember($this->company, ['client_admin' => null]);

        $this->withToken($this->tokenFor($admin, $this->company))
            ->postJson('/api/v1/sites', ['code' => 'SFX', 'name' => 'Usine Sfax', 'country' => 'TN', 'capacity_kg' => '2500.500'])
            ->assertCreated()
            ->assertJsonPath('data.code', 'SFX')
            ->assertJsonPath('data.capacity_kg', '2500.500')
            ->assertJsonMissingPath('data.id');

        $this->assertSame($this->company->id, DB::table('sites')->where('code', 'SFX')->value('company_id'));
    }

    public function test_site_creation_requires_permission(): void
    {
        $site = $this->makeSite($this->company, 'SFX');
        $operator = $this->makeMember($this->company, ['workshop_operator' => $site]);

        $this->withToken($this->tokenFor($operator, $this->company))
            ->postJson('/api/v1/sites', ['code' => 'MON', 'name' => 'Monastir', 'country' => 'TN'])
            ->assertForbidden();
    }

    public function test_site_code_is_unique_per_company_only(): void
    {
        $this->makeSite($this->makeCompany(), 'SFX');
        $this->makeSite($this->company, 'SFX');
        $admin = $this->makeMember($this->company, ['client_admin' => null]);

        $this->withToken($this->tokenFor($admin, $this->company))
            ->postJson('/api/v1/sites', ['code' => 'SFX', 'name' => 'Dup', 'country' => 'TN'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['code']]);
    }

    public function test_listing_shows_only_permitted_sites_of_the_current_company(): void
    {
        $sfax = $this->makeSite($this->company, 'SFX');
        $this->makeSite($this->company, 'MON');
        $this->makeSite($this->makeCompany(), 'OTH');
        $manager = $this->makeMember($this->company, ['environment_manager' => $sfax]);

        $this->withToken($this->tokenFor($manager, $this->company))
            ->getJson('/api/v1/sites')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.ulid', $sfax->ulid);
    }

    public function test_zone_cannot_reference_a_site_of_another_company(): void
    {
        $foreignSite = $this->makeSite($this->makeCompany(), 'OTH');

        $this->expectException(QueryException::class);

        DB::table('zones')->insert([
            'ulid' => '01JC2Z8M4V7H3K9P2RQW5X6Y8Z', 'company_id' => $this->company->id, 'site_id' => $foreignSite->id,
            'zone_type_id' => DB::table('zone_types')->value('id'), 'code' => 'Z1', 'name' => 'Zone',
        ]);
    }

    public function test_zone_belongs_to_the_current_company(): void
    {
        $site = $this->makeSite($this->company, 'SFX');

        $zone = $this->tenant()->runAs($this->company, fn () => Zone::query()->create([
            'site_id' => $site->id, 'zone_type_id' => DB::table('zone_types')->where('code', 'waste_storage')->value('id'),
            'code' => 'AIRE', 'name' => 'Aire de stockage',
        ]));

        $this->assertSame($this->company->id, $zone->company_id);
    }
}
