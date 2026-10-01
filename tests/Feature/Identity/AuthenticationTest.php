<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

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
}
