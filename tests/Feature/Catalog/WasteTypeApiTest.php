<?php

namespace Tests\Feature\Catalog;

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\Company;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class WasteTypeApiTest extends TestCase
{
    use InteractsWithTenants;
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([ReferenceDataSeeder::class, AuthorizationSeeder::class]);
        $this->company = $this->makeCompany();
        $this->admin = $this->makeMember($this->company, ['client_admin' => null]);
    }

    public function test_custom_type_with_composition_and_extra_attributes(): void
    {
        $this->post_(['code' => 'CHUTES-CO', 'name' => 'Chutes coton', 'waste_family' => 'textile_product',
            'default_composition' => [['material' => 'CO', 'pct' => 80], ['material' => 'PES', 'pct' => '20.00']],
            'extra_attributes' => ['note' => 'denim'], 'density_kg_m3' => '150.5'])
            ->assertCreated()
            ->assertJsonPath('data.default_composition', [['material' => 'CO', 'pct' => '80.00'], ['material' => 'PES', 'pct' => '20.00']])
            ->assertJsonPath('data.extra_attributes.note', 'denim')
            ->assertJsonPath('data.is_hazardous', false);

        $row = DB::table('waste_types')->where('code', 'CHUTES-CO')->first(['company_id', 'default_unit_id', 'waste_catalog_item_id']);
        $this->assertSame($this->company->id, $row->company_id);
        $this->assertSame(DB::table('units')->where('code', 'kg')->value('id'), $row->default_unit_id);
        $this->assertNull($row->waste_catalog_item_id);
    }

    public function test_activation_from_catalog_copies_defaults(): void
    {
        $this->post_(['catalog_item' => 'oils', 'code' => 'HUILES'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Huiles')
            ->assertJsonPath('data.is_hazardous', true);

        $row = DB::table('waste_types')->where('code', 'HUILES')->first(['waste_catalog_item_id', 'waste_family_id']);
        $this->assertSame(DB::table('waste_catalog_items')->where('code', 'oils')->value('id'), $row->waste_catalog_item_id);
        $this->assertSame(DB::table('waste_families')->where('code', 'oil')->value('id'), $row->waste_family_id);
    }

    public function test_composition_must_total_100(): void
    {
        $this->post_(['code' => 'X', 'name' => 'X', 'waste_family' => 'textile_product',
            'default_composition' => [['material' => 'CO', 'pct' => 80], ['material' => 'PES', 'pct' => 10]]])
            ->assertStatus(422)->assertJsonStructure(['errors' => ['default_composition']]);
    }

    public function test_hazardous_regulatory_code_forces_hazardous_and_must_match_country_system(): void
    {
        DB::table('regulatory_waste_codes')->insert([
            ['code_system' => 'TN', 'code' => 'TN-99*', 'level' => 3, 'label' => '{}', 'is_hazardous' => true],
            ['code_system' => 'EU_LOW', 'code' => '04 02 22', 'level' => 3, 'label' => '{}', 'is_hazardous' => false],
        ]);

        $this->post_(['code' => 'BOUES', 'name' => 'Boues', 'waste_family' => 'chemical_sludge', 'regulatory_code' => 'TN-99*', 'is_hazardous' => false])
            ->assertCreated()->assertJsonPath('data.is_hazardous', true);

        $this->post_(['code' => 'FR', 'name' => 'FR code', 'waste_family' => 'textile_product', 'regulatory_code' => '04 02 22'])
            ->assertStatus(422)->assertJsonStructure(['errors' => ['regulatory_code']]);
    }

    public function test_code_is_unique_per_company_and_listing_is_scoped(): void
    {
        $this->post_(['code' => 'CARTON', 'name' => 'Carton', 'waste_family' => 'packaging'])->assertCreated();
        $this->post_(['code' => 'CARTON', 'name' => 'Carton bis', 'waste_family' => 'packaging'])->assertStatus(422);

        $other = $this->makeCompany();
        $otherAdmin = $this->makeMember($other, ['client_admin' => null]);
        $this->withToken($this->tokenFor($otherAdmin, $other))
            ->postJson('/api/v1/waste-types', ['code' => 'CARTON', 'name' => 'Carton', 'waste_family' => 'packaging'])->assertCreated();

        $this->withToken($this->tokenFor($this->admin, $this->company))
            ->getJson('/api/v1/waste-types')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_permissions_are_enforced(): void
    {
        $operator = $this->makeMember($this->company, ['workshop_operator' => $this->makeSite($this->company, 'SFX')]);
        $token = $this->tokenFor($operator, $this->company);

        $this->withToken($token)->getJson('/api/v1/waste-types')->assertForbidden();
        $this->withToken($token)->postJson('/api/v1/waste-types', ['code' => 'X', 'name' => 'X', 'waste_family' => 'packaging'])->assertForbidden();
    }

    public function test_duplicate_code_is_rejected_by_the_database(): void
    {
        $this->post_(['code' => 'DUP', 'name' => 'Dup', 'waste_family' => 'packaging'])->assertCreated();

        $this->expectException(QueryException::class);
        DB::table('waste_types')->insert([
            'ulid' => '01JC2Z8M4V7H3K9P2RQW5X6Y8Z', 'company_id' => $this->company->id, 'code' => 'DUP', 'name' => 'Dup',
            'waste_family_id' => DB::table('waste_families')->value('id'), 'default_unit_id' => DB::table('units')->value('id'), 'activated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function post_(array $payload): TestResponse
    {
        return $this->withToken($this->tokenFor($this->admin, $this->company))->postJson('/api/v1/waste-types', $payload);
    }
}
