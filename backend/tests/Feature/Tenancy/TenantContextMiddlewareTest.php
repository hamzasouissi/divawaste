<?php

namespace Tests\Feature\Tenancy;

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Models\CompanyUser;
use App\Modules\Tenancy\TenantContext;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TenantContextMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    public function test_token_bound_to_a_company_resolves_that_company(): void
    {
        [$user, $company] = $this->member();

        $this->withToken($this->tokenFor($user, $company))
            ->getJson('/api/v1/company')
            ->assertOk()
            ->assertJsonPath('data.ulid', $company->ulid);

        $this->assertFalse(app(TenantContext::class)->hasTenant(), 'context must be cleared after the request');
    }

    public function test_token_bound_to_a_company_without_membership_is_forbidden(): void
    {
        [$user] = $this->member();
        $other = Company::factory()->active()->create();

        $this->withToken($this->tokenFor($user, $other))
            ->getJson('/api/v1/company')
            ->assertForbidden()
            ->assertHeader('Content-Type', 'application/problem+json');
    }

    public function test_suspended_membership_is_forbidden(): void
    {
        [$user, $company] = $this->member();
        DB::table('company_users')->where('user_id', $user->id)->update(['status' => 'suspended']);

        $this->withToken($this->tokenFor($user, $company))->getJson('/api/v1/company')->assertForbidden();
    }

    public function test_inactive_company_is_forbidden_by_default(): void
    {
        [$user, $company] = $this->member();
        DB::table('companies')->where('id', $company->id)->update(['status' => 'pending_approval']);

        $this->withToken($this->tokenFor($user, $company))->getJson('/api/v1/company')->assertForbidden();
    }

    public function test_token_without_company_binding_is_forbidden(): void
    {
        [$user] = $this->member();

        $this->withToken($user->createToken('no-company')->plainTextToken)->getJson('/api/v1/company')->assertForbidden();
    }

    /**
     * @return array{User, Company}
     */
    private function member(): array
    {
        $company = Company::factory()->active()->create();
        $user = User::factory()->create();
        app(TenantContext::class)->runAs($company, fn () => CompanyUser::query()->create(['user_id' => $user->id, 'joined_at' => now()]));

        return [$user, $company];
    }

    private function tokenFor(User $user, Company $company): string
    {
        $token = $user->createToken('device');
        $token->accessToken->forceFill(['company_id' => $company->id])->save();

        return $token->plainTextToken;
    }
}
