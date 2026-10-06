<?php

namespace Tests\Feature\Documents;

use App\Modules\Catalog\Models\WasteType;
use App\Modules\Documents\Models\AnnualDeclaration;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\HazardousWasteManifest;
use App\Modules\Pickups\Models\Pickup;
use App\Modules\Sites\Models\Site;
use App\Modules\Tenancy\Models\Company;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class ComplianceModelsTest extends TestCase
{
    use InteractsWithTenants;
    use RefreshDatabase;

    private Company $company;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([ReferenceDataSeeder::class, AuthorizationSeeder::class]);

        $this->company = $this->makeCompany();
        $this->site = $this->makeSite($this->company, 'SFX');
    }

    public function test_hazardous_manifest_and_annual_declaration_are_tenant_bound(): void
    {
        $document = $this->tenant()->runAs($this->company, fn () => Document::query()->create([
            'document_type' => 'hazardous_manifest',
            'title' => 'Manifest 2026',
            'status' => 'draft',
            'site_id' => $this->site->id,
            'is_regulatory' => true,
        ]));

        $provider = $this->makeCompany('provider');
        $pickup = $this->tenant()->runAs($this->company, fn () => Pickup::query()->create([
            'pickup_number' => 'PICK-2026-0001',
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

        $manifest = $this->tenant()->runAs($this->company, fn () => HazardousWasteManifest::query()->create([
            'pickup_id' => $pickup->id,
            'site_id' => $this->site->id,
            'document_id' => $document->id,
            'status' => 'draft',
            'regulatory_waste_code_id' => $regulatoryCodeId,
            'waste_type_id' => $wasteType->id,
            'package_count' => 2,
            'declared_weight_kg' => '12.500',
            'producer_snapshot' => ['name' => 'SFX'],
            'receiver_snapshot' => ['name' => 'Receiver'],
            'manifest_number' => 'HM-2026-0001',
        ]));

        $this->assertSame($this->company->id, $manifest->company_id);
        $this->assertNotSame('', $manifest->ulid);
        $this->assertSame('draft', $manifest->status->value ?? $manifest->status);

        $declaration = $this->tenant()->runAs($this->company, fn () => AnnualDeclaration::query()->create([
            'site_id' => $this->site->id,
            'format_code' => 'ANGED',
            'reporting_year' => 2026,
            'status' => 'draft',
            'data' => ['total_kg' => '12.500'],
        ]));

        $this->assertSame($this->company->id, $declaration->company_id);
        $this->assertNotSame('', $declaration->ulid);
        $this->assertSame('draft', $declaration->status->value ?? $declaration->status);
    }
}
