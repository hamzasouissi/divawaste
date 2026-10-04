<?php

namespace Tests\Feature\Documents;

use App\Modules\Catalog\Models\WasteType;
use App\Modules\Documents\Models\Document;
use App\Modules\Pickups\Models\Pickup;
use App\Modules\Sites\Models\Site;
use App\Modules\Tenancy\Models\Company;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class ComplianceApiTest extends TestCase
{
    use InteractsWithTenants;
    use RefreshDatabase;

    private Company $company;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            ReferenceDataSeeder::class,
            AuthorizationSeeder::class,
        ]);

        $this->company = $this->makeCompany();
        $this->site = $this->makeSite($this->company, 'CMP');
    }

    public function test_pickup_manifest_generation_endpoint_creates_a_manifest(): void
    {
        $manager = $this->makeMember($this->company, ['environment_manager' => $this->site]);
        $provider = $this->makeCompany('provider');

        $pickup = $this->tenant()->runAs($this->company, fn () => Pickup::query()->create([
            'pickup_number' => 'PUK-2026-0001',
            'site_id' => $this->site->id,
            'provider_company_id' => $provider->id,
            'requested_date' => now()->addDay()->toDateString(),
            'currency_code' => 'TND',
        ]));

        $regulatoryCodeId = DB::table('regulatory_waste_codes')->insertGetId([
            'code_system' => 'TN',
            'code' => 'D-13',
            'level' => 1,
            'label' => json_encode(['fr' => 'Déchets dangereux']),
            'is_hazardous' => true,
            'is_selectable' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $wasteType = $this->tenant()->runAs($this->company, fn () => WasteType::query()->create([
            'code' => 'CHUTES',
            'name' => 'Chutes',
            'waste_family_id' => DB::table('waste_families')->value('id'),
            'regulatory_waste_code_id' => $regulatoryCodeId,
            'default_unit_id' => DB::table('units')->value('id'),
            'is_hazardous' => true,
            'activated_at' => now(),
            'default_composition' => [['material' => 'CO', 'pct' => 100]],
        ]));

        $response = $this->withToken($this->tokenFor($manager, $this->company))
            ->postJson("/api/v1/pickups/{$pickup->ulid}/manifests", [
                'waste_type' => $wasteType->ulid,
                'regulatory_waste_code_id' => $regulatoryCodeId,
                'package_count' => 2,
                'declared_weight_kg' => '12.500',
                'producer_snapshot' => ['name' => 'Site CMP'],
                'transporter_snapshot' => ['company' => 'Carrier'],
                'receiver_snapshot' => ['company' => 'Receiver'],
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.status', 'draft');
        $response->assertJsonPath('data.pickup_id', $pickup->ulid);
        $this->assertDatabaseHas('hazardous_waste_manifests', [
            'pickup_id' => $pickup->id,
            'site_id' => $this->site->id,
            'company_id' => $this->company->id,
        ]);
    }

    public function test_yearly_declaration_endpoint_creates_a_declaration(): void
    {
        $manager = $this->makeMember($this->company, ['environment_manager' => $this->site]);

        $response = $this->withToken($this->tokenFor($manager, $this->company))
            ->postJson("/api/v1/sites/{$this->site->ulid}/declarations", [
                'format_code' => 'ANGED',
                'reporting_year' => 2026,
                'data' => ['total_kg' => '12.500', 'sites' => [$this->site->ulid]],
            ]);

        $response->assertCreated();
        $response->assertJsonPath('status', 'draft');
        $this->assertDatabaseHas('annual_declarations', [
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'format_code' => 'ANGED',
            'reporting_year' => 2026,
        ]);
    }
}
