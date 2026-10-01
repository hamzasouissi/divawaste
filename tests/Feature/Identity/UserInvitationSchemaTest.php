<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\UserInvitation;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class UserInvitationSchemaTest extends TestCase
{
    use InteractsWithTenants;
    use RefreshDatabase;

    public function test_only_one_pending_invitation_per_email_and_company(): void
    {
        $this->seed([ReferenceDataSeeder::class, AuthorizationSeeder::class]);
        $company = $this->makeCompany();
        $admin = $this->makeMember($company, ['client_admin' => null]);

        $invite = fn () => UserInvitation::query()->create([
            'email' => 'new@example.tn',
            'role_id' => Role::findByName('auditor')->id,
            'token_hash' => hash('sha256', Str::random(40)),
            'invited_by_user_id' => $admin->id,
            'expires_at' => now()->addDays(UserInvitation::VALIDITY_DAYS),
            'last_sent_at' => now(),
        ]);

        $this->tenant()->runAs($company, function () use ($invite) {
            $first = $invite();
            $this->assertFalse($first->isExpired());

            // Once accepted, the NULL flag frees the slot for a new invitation.
            $first->forceFill(['status' => 'accepted', 'pending_flag' => null])->save();
            $invite();

            $this->expectException(QueryException::class);
            $invite();
        });
    }

    public function test_invitation_expires_after_seven_days(): void
    {
        $invitation = new UserInvitation(['expires_at' => now()->addDays(UserInvitation::VALIDITY_DAYS)]);

        $this->travel(UserInvitation::VALIDITY_DAYS)->days();
        $this->travel(1)->minutes();

        $this->assertTrue($invitation->isExpired());
    }
}
