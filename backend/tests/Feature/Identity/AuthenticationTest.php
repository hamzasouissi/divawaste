<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Models\CompanyUser;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use InteractsWithTenants;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([ReferenceDataSeeder::class, AuthorizationSeeder::class]);
    }

    public function test_login_is_throttled_after_five_attempts_per_minute(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'unknown@example.test',
                'password' => 'wrong-password',
            ])->assertUnauthorized();
        }

        $this->postJson('/api/v1/auth/login', [
            'email' => 'unknown@example.test',
            'password' => 'wrong-password',
        ])->assertTooManyRequests();
    }

    public function test_login_issues_company_bound_token_for_single_active_membership(): void
    {
        $company = $this->makeCompany();
        $user = User::factory()->create(['password' => 'a-strong-password-123']);
        $this->addMembership($user, $company);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'a-strong-password-123',
        ])->assertOk()
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.requires_company_selection', false)
            ->assertJsonPath('data.company.ulid', $company->ulid)
            ->assertJsonPath('data.user.ulid', $user->ulid);

        $this->withToken($response->json('data.access_token'))
            ->getJson('/api/v1/company')
            ->assertOk()
            ->assertJsonPath('data.ulid', $company->ulid);
    }

    public function test_login_requires_company_selection_when_user_has_multiple_memberships(): void
    {
        $first = $this->makeCompany();
        $second = $this->makeCompany();
        $user = User::factory()->create(['password' => 'a-strong-password-123']);
        $this->addMembership($user, $first);
        $this->addMembership($user, $second);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'a-strong-password-123',
        ])->assertOk()
            ->assertJsonPath('data.requires_company_selection', true)
            ->assertJsonCount(2, 'data.companies')
            ->assertJsonMissingPath('data.access_token');

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'a-strong-password-123',
            'company' => $second->ulid,
        ])->assertOk()
            ->assertJsonPath('data.company.ulid', $second->ulid);

        $this->withToken($response->json('data.access_token'))
            ->getJson('/api/v1/company')
            ->assertOk()
            ->assertJsonPath('data.ulid', $second->ulid);
    }

    public function test_login_rejects_invalid_credentials_inactive_and_locked_users(): void
    {
        $user = User::factory()->create(['password' => 'a-strong-password-123']);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertUnauthorized()->assertJsonPath('code', 'UNAUTHENTICATED');

        $user->forceFill(['status' => 'disabled'])->save();
        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'a-strong-password-123',
        ])->assertUnauthorized()->assertJsonPath('code', 'UNAUTHENTICATED');

        $user->forceFill(['status' => 'active', 'locked_until' => now()->addMinutes(10)])->save();
        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'a-strong-password-123',
        ])->assertUnauthorized()->assertJsonPath('code', 'UNAUTHENTICATED');
    }

    public function test_me_requires_authentication_and_returns_problem_json(): void
    {
        $this->getJson('/api/v1/me')
            ->assertUnauthorized()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('code', 'UNAUTHENTICATED');
    }

    public function test_me_exposes_ulid_and_never_internal_id_or_secrets(): void
    {
        $user = User::factory()->create()->refresh();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/me')->assertOk();

        $response->assertJsonPath('data.ulid', $user->ulid);
        $this->assertArrayNotHasKey('id', $response->json('data'));
        $this->assertArrayNotHasKey('password', $response->json('data'));
    }

    public function test_password_is_hashed_with_argon2id(): void
    {
        $user = User::factory()->create(['password' => 'a-strong-password-123']);

        $this->assertStringStartsWith('$argon2id$', (string) DB::table('users')->where('id', $user->id)->value('password'));
    }

    public function test_two_factor_secret_is_encrypted_at_rest(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['two_factor_secret' => 'JBSWY3DPEHPK3PXP'])->save();

        $raw = (string) DB::table('users')->where('id', $user->id)->value('two_factor_secret');

        $this->assertNotSame('JBSWY3DPEHPK3PXP', $raw);
        $this->assertSame('JBSWY3DPEHPK3PXP', $user->fresh()?->two_factor_secret);
    }

    public function test_email_is_normalized_and_globally_unique(): void
    {
        $user = User::factory()->create(['email' => '  Ops@Example.TN ']);
        $this->assertSame('ops@example.tn', $user->email);

        $this->expectException(QueryException::class);
        User::factory()->create(['email' => 'OPS@example.tn']);
    }

    public function test_locked_user_is_not_active(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['failed_login_attempts' => 5, 'locked_until' => now()->addMinutes(15)])->save();

        $this->assertTrue($user->isLocked());
        $this->assertFalse($user->isActive());
    }

    private function addMembership(User $user, Company $company): void
    {
        $this->tenant()->runAs($company, fn () => CompanyUser::query()->create([
            'user_id' => $user->id,
            'joined_at' => now(),
        ]));
    }
}
