<?php

namespace Tests\Feature\Tenancy;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TenancySchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_deferred_foreign_keys_exist(): void
    {
        $names = collect(DB::select(
            'select constraint_name as name from information_schema.referential_constraints where constraint_schema = database()'
        ))->pluck('name')->all();

        foreach (['fk_users_last_company', 'fk_roles_company', 'fk_companies_logo', 'fk_pat_company'] as $fk) {
            $this->assertContains($fk, $names);
        }
    }

    public function test_key_unique_constraints_exist(): void
    {
        $this->assertTrue(Schema::hasIndex('companies', 'uq_companies_country_tax_id', 'unique'));
        $this->assertTrue(Schema::hasIndex('company_users', 'uq_company_users_company_user', 'unique'));
        $this->assertTrue(Schema::hasIndex('stored_files', 'uq_stored_files_company_id_id', 'unique'));
        $this->assertTrue(Schema::hasIndex('number_sequences', 'uq_number_sequences_key', 'unique'));
    }
}
