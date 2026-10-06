<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Actions\AttemptLogin;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Notifications\AccountLockedNotification;
use App\Modules\Identity\Notifications\ResetPasswordNotification;
use App\Modules\Identity\Notifications\VerifyEmailNotification;
use App\Modules\Identity\Support\EmailVerification;
use App\Modules\Tenancy\Models\Company;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Notification;
use PragmaRX\Google2FA\Google2FA;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * M0-02 / M0-09: lockout, logout, password reset, TOTP, email confirmation, access snapshot.
 */
class AccountSecurityTest extends TestCase
{
    use InteractsWithTenants;

    private const PASSWORD = 'a-strong-password-123';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([ReferenceDataSeeder::class, AuthorizationSeeder::class]);
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public function test_account_is_locked_after_five_failed_passwords_and_the_user_is_notified(): void
    {
        Notification::fake();
        $user = User::factory()->create(['password' => self::PASSWORD]);

        for ($attempt = 1; $attempt <= AttemptLogin::MAX_FAILURES; $attempt++) {
            $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong-password'])->assertUnauthorized();
        }

        $this->assertTrue($user->fresh()->isLocked());
        Notification::assertSentTo($user, AccountLockedNotification::class);

        // The right password does not help while the lock lasts.
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertUnauthorized();

        $this->travel(AttemptLogin::LOCK_MINUTES + 1)->minutes();
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertOk();
        $this->assertSame(0, $user->fresh()->failed_login_attempts);
    }

    public function test_a_successful_login_resets_the_failure_counter(): void
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong-password'])->assertUnauthorized();
        $this->assertSame(1, $user->fresh()->failed_login_attempts);

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertOk();
        $this->assertSame(0, $user->fresh()->failed_login_attempts);
    }

    public function test_logout_revokes_the_current_token(): void
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);
        $token = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])->json('data.access_token');

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertNoContent();

        app('auth')->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_forgot_password_never_reveals_accounts_and_reset_enforces_the_policy(): void
    {
        Notification::fake();
        $user = User::factory()->create(['password' => self::PASSWORD]);
        $oldToken = $user->createToken('api')->plainTextToken;

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.test'])->assertNoContent();
        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertNoContent();

        $resetToken = null;
        Notification::assertSentTo($user, ResetPasswordNotification::class, function (ResetPasswordNotification $notification) use ($user, &$resetToken) {
            parse_str((string) parse_url($notification->toMail($user)->actionUrl, PHP_URL_QUERY), $query);
            $resetToken = $query['token'];

            return str_starts_with($notification->toMail($user)->actionUrl, config('app.frontend_url'));
        });

        $this->postJson('/api/v1/auth/reset-password', ['email' => $user->email, 'token' => $resetToken, 'password' => 'short1'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->postJson('/api/v1/auth/reset-password', ['email' => $user->email, 'token' => $resetToken, 'password' => 'another-strong-pass-9'])
            ->assertNoContent();

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'another-strong-pass-9'])->assertOk();
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertUnauthorized();

        // Sessions opened before the reset are gone and the link cannot be replayed.
        app('auth')->forgetGuards();
        $this->withToken($oldToken)->getJson('/api/v1/me')->assertUnauthorized();
        $this->postJson('/api/v1/auth/reset-password', ['email' => $user->email, 'token' => $resetToken, 'password' => 'yet-another-pass-77'])
            ->assertUnprocessable();
    }

    public function test_totp_enrolment_then_login_requires_a_code_or_a_recovery_code(): void
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);
        $token = $user->createToken('api')->plainTextToken;

        $secret = $this->withToken($token)->postJson('/api/v1/me/two-factor')->assertOk()
            ->assertJsonStructure(['data' => ['secret', 'otpauth_url', 'qr_svg']])->json('data.secret');

        // Not active until a first code is confirmed.
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertOk()->assertJsonPath('data.requires_two_factor', false);

        $this->withToken($token)->postJson('/api/v1/me/two-factor/confirm', ['code' => '000000'])->assertUnprocessable();
        $codes = $this->withToken($token)->postJson('/api/v1/me/two-factor/confirm', ['code' => $this->currentCode($secret)])
            ->assertOk()->json('data.recovery_codes');
        $this->assertCount(8, $codes);

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertOk()->assertJsonPath('data.requires_two_factor', true)->assertJsonMissingPath('data.access_token');

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD, 'code' => '000000'])->assertUnauthorized();

        // A recovery code works once.
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD, 'recovery_code' => $codes[0]])
            ->assertOk()->assertJsonPath('data.user.two_factor_enabled', true);
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD, 'recovery_code' => $codes[0]])->assertUnauthorized();

        // A code already used (here: for the confirmation) is refused; the next time step is accepted.
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD, 'code' => $this->currentCode($secret)])->assertUnauthorized();
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD, 'code' => $this->currentCode($secret, 1)])
            ->assertOk()->assertJsonStructure(['data' => ['access_token']]);

        $this->withToken($token)->deleteJson('/api/v1/me/two-factor', ['password' => 'wrong-password'])->assertUnprocessable();
        $this->withToken($token)->deleteJson('/api/v1/me/two-factor', ['password' => self::PASSWORD])->assertNoContent();
        $this->assertNull($user->fresh()->two_factor_confirmed_at);
    }

    public function test_email_link_is_valid_24_hours_and_moves_the_company_to_platform_review(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $company = Company::factory()->create();
        $company->forceFill(['primary_contact_user_id' => $user->id])->save();

        $this->withToken($user->createToken('api')->plainTextToken)->postJson('/api/v1/auth/email/resend')->assertNoContent();
        Notification::assertSentTo($user, VerifyEmailNotification::class);

        $link = EmailVerification::parameters($user);

        $this->postJson('/api/v1/auth/email/verify', ['signature' => str_repeat('0', 64)] + $link)->assertUnprocessable();

        $this->travel(EmailVerification::VALIDITY_HOURS + 1)->hours();
        $this->postJson('/api/v1/auth/email/verify', $link)->assertUnprocessable();
        $this->assertNull($user->fresh()->email_verified_at);
        $this->travelBack();

        $this->postJson('/api/v1/auth/email/verify', $link)->assertOk()->assertJsonPath('data.verified', true);
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertSame('pending_approval', $company->fresh()->status->value);
    }

    public function test_changing_the_password_requires_the_current_one(): void
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);
        $token = $user->createToken('api')->plainTextToken;

        $this->withToken($token)->putJson('/api/v1/me/password', ['current_password' => 'nope', 'password' => 'another-strong-pass-9'])
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->withToken($token)->putJson('/api/v1/me/password', ['current_password' => self::PASSWORD, 'password' => 'another-strong-pass-9'])
            ->assertNoContent();

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'another-strong-pass-9'])->assertOk();
    }

    public function test_access_snapshot_lists_permissions_per_site_for_the_current_company_only(): void
    {
        $company = $this->makeCompany();
        $siteA = $this->makeSite($company, 'A');
        $siteB = $this->makeSite($company, 'B');
        $other = $this->makeCompany();
        $this->makeSite($other, 'X');
        $user = $this->makeMember($company, ['workshop_operator' => $siteA]);

        $data = $this->withToken($this->tokenFor($user, $company))->getJson('/api/v1/me/access')->assertOk()->json('data');

        $this->assertSame($company->ulid, $data['company']['ulid']);
        $this->assertSame([], $data['company_permissions']);
        $this->assertSame([$siteA->ulid], array_column($data['sites'], 'ulid'));
        $this->assertContains('lots.create', $data['sites'][0]['permissions']);
        $this->assertNotContains($siteB->ulid, array_column($data['sites'], 'ulid'));

        $admin = $this->makeMember($company, ['client_admin' => null]);
        $data = $this->withToken($this->tokenFor($admin, $company))->getJson('/api/v1/me/access')->assertOk()->json('data');

        $this->assertContains('users.manage', $data['company_permissions']);
        $this->assertEqualsCanonicalizing([$siteA->ulid, $siteB->ulid], array_column($data['sites'], 'ulid'));
    }

    private function currentCode(string $secret, int $stepOffset = 0): string
    {
        $totp = app(Google2FA::class);

        return $totp->oathTotp($secret, $totp->getTimestamp() + $stepOffset);
    }
}
