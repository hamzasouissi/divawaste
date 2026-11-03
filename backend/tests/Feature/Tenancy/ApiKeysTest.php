<?php

namespace Tests\Feature\Tenancy;

use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * API keys of a company: made and revoked by who may change the company, shown once, acting with their maker's rights
 * for this company only.
 */
class ApiKeysTest extends TestCase
{
    use InteractsWithTenants;
    use RefreshDatabase;

    public function test_an_api_key_is_shown_once_works_for_its_company_and_can_be_revoked(): void
    {
        $this->seed([ReferenceDataSeeder::class, AuthorizationSeeder::class]);
        $company = $this->makeCompany();
        $admin = $this->tokenFor($this->makeMember($company, ['client_admin' => null]), $company);
        $operator = $this->tokenFor($this->makeMember($company, ['workshop_operator' => null]), $company);

        $this->withToken($operator)->postJson('/api/v1/company/api-keys', ['name' => 'ERP'])->assertForbidden();
        $this->app['auth']->forgetGuards();
        $key = $this->withToken($admin)->postJson('/api/v1/company/api-keys', ['name' => 'ERP'])->assertCreated()->json('data');
        $this->assertNotEmpty($key['token']);
        $listed = $this->withToken($admin)->getJson('/api/v1/company/api-keys')->assertOk()->json('data');
        $this->assertSame('ERP', $listed[0]['name']);
        $this->assertArrayNotHasKey('token', $listed[0]);

        // the key reads the company's data as its maker would
        $this->app['auth']->forgetGuards();
        $this->withToken($key['token'])->getJson('/api/v1/company')->assertOk()->assertJsonPath('data.ulid', $company->ulid);

        $this->app['auth']->forgetGuards();
        $this->withToken($admin)->deleteJson('/api/v1/company/api-keys/'.$key['id'])->assertNoContent();
        $this->app['auth']->forgetGuards();
        $this->withToken($key['token'])->getJson('/api/v1/company')->assertUnauthorized();
    }

    public function test_searching_or_asking_davi_does_not_use_up_the_limit_of_creating_a_key(): void
    {
        // Found while writing the user guides (2026-11-02): bare "throttle:N,1" limits shared one count per user, so ten
        // searches or questions to DAVI in a minute refused the next key, or the signature of a slip.
        $this->seed([ReferenceDataSeeder::class, AuthorizationSeeder::class]);
        $company = $this->makeCompany();
        $admin = $this->tokenFor($this->makeMember($company, ['client_admin' => null]), $company);

        for ($i = 0; $i < 12; $i++) {
            $this->withToken($admin)->getJson('/api/v1/search?q=lot')->assertOk();
        }
        $this->withToken($admin)->postJson('/api/v1/company/api-keys', ['name' => 'ERP'])->assertCreated();
    }
}
