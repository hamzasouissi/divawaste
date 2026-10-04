<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\UserInvitation;
use App\Modules\Identity\Notifications\InvitationNotification;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\ProvisionalReferenceSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * M0-08 and the configurable role × permission matrix (cahier §2).
 */
class UsersAndRolesTest extends TestCase
{
    use InteractsWithTenants;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([ReferenceDataSeeder::class, AuthorizationSeeder::class, ProvisionalReferenceSeeder::class]);
        $this->withoutMiddleware(ThrottleRequests::class);
        Notification::fake();
    }

    public function test_invitation_with_role_and_sites_is_accepted_by_a_new_user(): void
    {
        $company = $this->makeCompany();
        $siteA = $this->makeSite($company, 'A');
        $this->makeSite($company, 'B');
        $admin = $this->makeMember($company, ['client_admin' => null]);
        $token = $this->tokenFor($admin, $company);

        $this->withToken($token)->postJson('/api/v1/invitations', [
            'email' => 'Operateur@Usine.example', 'first_name' => 'Sami', 'role' => 'workshop_operator', 'sites' => [$siteA->ulid],
        ])->assertCreated()->assertJsonPath('data.email', 'operateur@usine.example')->assertJsonPath('data.status', 'pending');

        // One pending invitation per address.
        $this->withToken($token)->postJson('/api/v1/invitations', ['email' => 'operateur@usine.example', 'role' => 'workshop_operator'])
            ->assertConflict()->assertJsonPath('code', 'INVITATION_PENDING');

        $link = $this->sentInvitationToken('operateur@usine.example');
        $this->assertDatabaseMissing('user_invitations', ['token_hash' => $link]);

        app('auth')->forgetGuards();
        $this->getJson("/api/v1/auth/invitations/{$link}")->assertOk()
            ->assertJsonPath('data.company', $company->legal_name)->assertJsonPath('data.has_account', false);

        $this->postJson("/api/v1/auth/invitations/{$link}/accept", ['first_name' => 'Sami', 'last_name' => 'Gharbi', 'password' => 'short', 'accept_terms' => true, 'accept_privacy' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postJson("/api/v1/auth/invitations/{$link}/accept", ['first_name' => 'Sami', 'last_name' => 'Gharbi', 'password' => 'a-strong-password-123', 'accept_terms' => true, 'accept_privacy' => true])
            ->assertCreated();

        // Used once.
        $this->getJson("/api/v1/auth/invitations/{$link}")->assertUnprocessable();

        $login = $this->postJson('/api/v1/auth/login', ['email' => 'operateur@usine.example', 'password' => 'a-strong-password-123'])->assertOk();
        $this->assertTrue($login->json('data.user.email_verified'));
        app('auth')->forgetGuards();
        $access = $this->withToken($login->json('data.access_token'))->getJson('/api/v1/me/access')->assertOk()->json('data');
        $this->assertSame([$siteA->ulid], array_column($access['sites'], 'ulid'));
        $this->assertContains('lots.create', $access['sites'][0]['permissions']);
        $this->assertSame(2, DB::table('legal_acceptances')->where('context', 'invitation')->count());
    }

    public function test_invitation_expires_after_seven_days_and_can_be_resent_or_revoked(): void
    {
        $company = $this->makeCompany();
        $admin = $this->makeMember($company, ['client_admin' => null]);
        $token = $this->tokenFor($admin, $company);

        $ulid = $this->withToken($token)->postJson('/api/v1/invitations', ['email' => 'direction@usine.example', 'role' => 'management_viewer'])
            ->assertCreated()->json('data.ulid');
        $first = $this->sentInvitationToken('direction@usine.example');

        $this->travel(UserInvitation::VALIDITY_DAYS)->days();
        $this->travel(1)->minutes();
        $this->getJson("/api/v1/auth/invitations/{$first}")->assertUnprocessable();
        $this->withToken($token)->getJson('/api/v1/invitations')->assertOk()->assertJsonPath('data.0.status', 'expired');

        Notification::fake();
        $this->withToken($token)->postJson("/api/v1/invitations/{$ulid}/resend")->assertOk()->assertJsonPath('data.send_count', 2);
        $second = $this->sentInvitationToken('direction@usine.example');
        app('auth')->forgetGuards();
        $this->getJson("/api/v1/auth/invitations/{$first}")->assertUnprocessable();
        $this->getJson("/api/v1/auth/invitations/{$second}")->assertOk();

        $this->withToken($token)->deleteJson("/api/v1/invitations/{$ulid}")->assertNoContent();
        app('auth')->forgetGuards();
        $this->getJson("/api/v1/auth/invitations/{$second}")->assertUnprocessable();
    }

    public function test_invitations_and_members_of_another_company_are_out_of_reach(): void
    {
        $company = $this->makeCompany();
        $admin = $this->makeMember($company, ['client_admin' => null]);
        $other = $this->makeCompany();
        $otherSite = $this->makeSite($other, 'X');
        $otherAdmin = $this->makeMember($other, ['client_admin' => null]);
        $otherInvitation = $this->withToken($this->tokenFor($otherAdmin, $other))
            ->postJson('/api/v1/invitations', ['email' => 'someone@other.example', 'role' => 'auditor'])->assertCreated()->json('data.ulid');

        $token = $this->tokenFor($admin, $company);
        $this->withToken($token)->postJson('/api/v1/invitations', ['email' => 'x@usine.example', 'role' => 'auditor', 'sites' => [$otherSite->ulid]])
            ->assertUnprocessable()->assertJsonValidationErrors('sites');
        $this->withToken($token)->deleteJson("/api/v1/invitations/{$otherInvitation}")->assertNotFound();
        $this->withToken($token)->postJson("/api/v1/users/{$otherAdmin->ulid}/suspend")->assertNotFound();
        $this->withToken($token)->putJson("/api/v1/users/{$otherAdmin->ulid}/roles", ['assignments' => []])->assertNotFound();
        $this->assertSame([$admin->ulid], array_column($this->withToken($token)->getJson('/api/v1/users')->assertOk()->json('data'), 'ulid'));

        // A provider role cannot be given by an industrial.
        $this->withToken($token)->postJson('/api/v1/invitations', ['email' => 'y@usine.example', 'role' => 'provider_admin'])->assertUnprocessable();
        $this->withToken($token)->postJson('/api/v1/invitations', ['email' => 'y@usine.example', 'role' => 'super_admin'])->assertUnprocessable();
    }

    public function test_roles_are_reassigned_per_site_and_the_last_administrator_is_protected(): void
    {
        $company = $this->makeCompany();
        $siteA = $this->makeSite($company, 'A');
        $siteB = $this->makeSite($company, 'B');
        $admin = $this->makeMember($company, ['client_admin' => null]);
        $member = $this->makeMember($company, ['workshop_operator' => $siteA]);
        $token = $this->tokenFor($admin, $company);

        $this->withToken($token)->putJson("/api/v1/users/{$member->ulid}/roles", ['assignments' => [
            ['role' => 'workshop_operator', 'site' => $siteA->ulid],
            ['role' => 'environment_manager', 'site' => $siteB->ulid],
        ]])->assertNoContent();

        $row = collect($this->withToken($token)->getJson('/api/v1/users')->json('data'))->firstWhere('ulid', $member->ulid);
        $this->assertEqualsCanonicalizing(
            [['workshop_operator', $siteA->ulid], ['environment_manager', $siteB->ulid]],
            array_map(fn ($a) => [$a['role'], $a['site']], $row['assignments']),
        );
        $this->assertTrue($this->tenant()->runAs($company, fn () => $member->can('pickups.request', $siteB)));
        $this->assertFalse($this->tenant()->runAs($company, fn () => $member->can('pickups.request', $siteA)));
        $this->assertDatabaseHas('audit_logs', ['company_id' => $company->id, 'auditable_type' => 'user_role_assignments', 'event' => 'created', 'actor_user_id' => $admin->id]);

        $this->withToken($token)->putJson("/api/v1/users/{$admin->ulid}/roles", ['assignments' => [['role' => 'management_viewer']]])
            ->assertConflict()->assertJsonPath('code', 'LAST_ADMINISTRATOR');
        $this->withToken($token)->postJson("/api/v1/users/{$admin->ulid}/suspend")->assertConflict();
        $this->withToken($token)->deleteJson("/api/v1/users/{$admin->ulid}")->assertConflict();

        // Suspension cuts access at once; removal takes the member out of the list.
        $memberToken = $this->tokenFor($member, $company);
        $this->withToken($memberToken)->getJson('/api/v1/company')->assertOk();
        $this->withToken($this->tokenFor($admin, $company))->postJson("/api/v1/users/{$member->ulid}/suspend")->assertNoContent();
        app('auth')->forgetGuards();
        $this->withToken($memberToken)->getJson('/api/v1/company')->assertUnauthorized();

        $this->withToken($this->tokenFor($admin, $company))->deleteJson("/api/v1/users/{$member->ulid}")->assertNoContent();
        $this->assertCount(1, $this->withToken($this->tokenFor($admin, $company))->getJson('/api/v1/users')->json('data'));
    }

    public function test_only_users_with_the_permission_manage_users(): void
    {
        $company = $this->makeCompany();
        $admin = $this->makeMember($company, ['client_admin' => null]);
        $manager = $this->makeMember($company, ['environment_manager' => null]);
        $token = $this->tokenFor($manager, $company);

        $this->withToken($token)->getJson('/api/v1/users')->assertOk();
        $this->withToken($token)->postJson('/api/v1/invitations', ['email' => 'z@usine.example', 'role' => 'auditor'])->assertForbidden();
        $this->withToken($token)->putJson("/api/v1/users/{$admin->ulid}/roles", ['assignments' => []])->assertForbidden();
        $this->withToken($token)->postJson('/api/v1/roles', ['name' => 'x', 'label' => 'X', 'permissions' => []])->assertForbidden();
    }

    public function test_company_creates_its_own_role_and_the_matrix_takes_effect_without_code_change(): void
    {
        $company = $this->makeCompany();
        $site = $this->makeSite($company, 'A');
        $admin = $this->makeMember($company, ['client_admin' => null]);
        $member = $this->makeMember($company);
        $token = $this->tokenFor($admin, $company);

        $catalog = $this->withToken($token)->getJson('/api/v1/roles')->assertOk()->json('data');
        $this->assertContains('lots.create', array_column($catalog['permissions'], 'name'));
        $this->assertNotContains('platform.companies.review', array_column($catalog['permissions'], 'name'));
        $this->assertNotContains('provider.pickups.respond', array_column($catalog['permissions'], 'name'));

        $this->withToken($token)->postJson('/api/v1/roles', ['name' => 'chef_coupe', 'label' => 'Chef de coupe', 'permissions' => ['platform.companies.review']])
            ->assertUnprocessable();
        $this->withToken($token)->postJson('/api/v1/roles', ['name' => 'client_admin', 'label' => 'Doublon', 'permissions' => []])->assertUnprocessable();
        $this->withToken($token)->postJson('/api/v1/roles', ['name' => 'chef_coupe', 'label' => 'Chef de coupe', 'permissions' => ['lots.view']])
            ->assertCreated()->assertJsonPath('data.is_system', false);

        $this->withToken($token)->putJson("/api/v1/users/{$member->ulid}/roles", ['assignments' => [['role' => 'chef_coupe', 'site' => $site->ulid]]])->assertNoContent();
        $can = fn (string $permission) => $this->tenant()->runAs($company, fn () => $member->can($permission, $site));
        $this->assertTrue($can('lots.view'));
        $this->assertFalse($can('lots.create'));

        $this->withToken($token)->putJson('/api/v1/roles/chef_coupe', ['label' => 'Chef de coupe', 'permissions' => ['lots.view', 'lots.create']])->assertOk();
        $this->assertTrue($can('lots.create'));

        // System roles are not editable by a client; a role in use cannot be deleted.
        $this->withToken($token)->putJson('/api/v1/roles/workshop_operator', ['label' => 'x', 'permissions' => []])->assertNotFound();
        $this->withToken($token)->deleteJson('/api/v1/roles/chef_coupe')->assertConflict()->assertJsonPath('code', 'ROLE_IN_USE');

        // Another company neither sees nor uses this role.
        $other = $this->makeCompany();
        $otherAdmin = $this->makeMember($other, ['client_admin' => null]);
        $otherToken = $this->tokenFor($otherAdmin, $other);
        $this->assertNotContains('chef_coupe', array_column($this->withToken($otherToken)->getJson('/api/v1/roles')->json('data.roles'), 'name'));
        $this->withToken($otherToken)->putJson('/api/v1/roles/chef_coupe', ['label' => 'x', 'permissions' => []])->assertNotFound();
    }

    public function test_platform_edits_a_system_role_for_every_company(): void
    {
        $company = $this->makeCompany();
        $site = $this->makeSite($company, 'A');
        $operator = $this->makeMember($company, ['workshop_operator' => $site]);
        $can = fn () => $this->tenant()->runAs($company, fn () => $operator->can('pickups.request', $site));
        $this->assertFalse($can());

        $staff = User::factory()->create();
        $staff->assignRole('super_admin');
        app('auth')->forgetGuards();
        $token = $staff->createToken('test')->plainTextToken;

        $current = collect($this->withToken($token)->getJson('/api/v1/platform/roles')->assertOk()->json('data.roles'))->firstWhere('name', 'workshop_operator');
        $this->withToken($token)->putJson('/api/v1/platform/roles/workshop_operator', ['permissions' => [...$current['permissions'], 'platform.plans.manage']])
            ->assertUnprocessable();
        $this->withToken($token)->putJson('/api/v1/platform/roles/workshop_operator', ['permissions' => [...$current['permissions'], 'pickups.request']])
            ->assertNoContent();
        $this->assertTrue($can());

        app('auth')->forgetGuards();
        $clientAdmin = $this->makeMember($company, ['client_admin' => null]);
        $this->withToken($this->tokenFor($clientAdmin, $company))->putJson('/api/v1/platform/roles/workshop_operator', ['permissions' => []])->assertForbidden();
    }

    private function sentInvitationToken(string $email): string
    {
        $token = null;
        Notification::assertSentOnDemand(InvitationNotification::class, function (InvitationNotification $notification, array $channels, AnonymousNotifiable $notifiable) use ($email, &$token) {
            if ($notifiable->routes['mail'] !== $email) {
                return false;
            }
            parse_str((string) parse_url($notification->toMail($notifiable)->actionUrl, PHP_URL_QUERY), $query);
            $token = $query['token'];

            return true;
        });

        return (string) $token;
    }
}
