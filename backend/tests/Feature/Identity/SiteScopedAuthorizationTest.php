<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Authorization\AccessResolver;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\UserRoleAssignment;
use App\Modules\Tenancy\Models\Company;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class SiteScopedAuthorizationTest extends TestCase
{
    use InteractsWithTenants;
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([ReferenceDataSeeder::class, AuthorizationSeeder::class]);
        $this->company = $this->makeCompany();
    }

    public function test_same_user_has_different_roles_on_different_sites(): void
    {
        $sfax = $this->makeSite($this->company, 'SFX');
        $monastir = $this->makeSite($this->company, 'MON');
        $user = $this->makeMember($this->company, ['environment_manager' => $sfax, 'workshop_operator' => $monastir]);

        $this->tenant()->runAs($this->company, function () use ($user, $sfax, $monastir) {
            $this->assertTrue($user->can('lots.delete', $sfax));
            $this->assertFalse($user->can('lots.delete', $monastir));
            $this->assertTrue($user->can('lots.create', $monastir));
            $this->assertSame([$sfax->id], app(AccessResolver::class)->siteIdsFor($user, 'lots.delete'));
        });
    }

    public function test_company_permissions_require_a_company_wide_assignment(): void
    {
        $site = $this->makeSite($this->company, 'SFX');
        $siteAdmin = $this->makeMember($this->company, ['client_admin' => $site]);
        $companyAdmin = $this->makeMember($this->company, ['client_admin' => null]);

        $this->tenant()->runAs($this->company, function () use ($siteAdmin, $companyAdmin) {
            $this->assertFalse($siteAdmin->can('users.manage'));
            $this->assertTrue($companyAdmin->can('users.manage'));
        });
    }

    public function test_company_wide_assignment_covers_sites_created_later(): void
    {
        $director = $this->makeMember($this->company, ['management_viewer' => null]);
        $later = $this->makeSite($this->company, 'NEW');

        $this->tenant()->runAs($this->company, function () use ($director, $later) {
            $this->assertTrue($director->can('lots.view', $later));
            $this->assertNull(app(AccessResolver::class)->siteIdsFor($director, 'lots.view'));
        });
    }

    public function test_cache_is_invalidated_when_an_assignment_is_removed(): void
    {
        $site = $this->makeSite($this->company, 'SFX');
        $user = $this->makeMember($this->company, ['environment_manager' => $site]);

        $this->tenant()->runAs($this->company, function () use ($user, $site) {
            $this->assertTrue($user->can('lots.view', $site));
            UserRoleAssignment::query()->where('user_id', $user->id)->firstOrFail()->delete();
            $this->assertFalse($user->can('lots.view', $site));
        });
    }

    public function test_permissions_of_another_company_do_not_apply(): void
    {
        $other = $this->makeCompany();
        $user = $this->makeMember($this->company, ['client_admin' => null]);
        $this->makeMember($other);

        $this->tenant()->runAs($other, fn () => $this->assertFalse($user->can('sites.manage')));
    }

    public function test_assignment_requires_membership(): void
    {
        $outsider = User::factory()->create();

        $this->expectException(QueryException::class);
        $this->tenant()->runAs($this->company, fn () => $this->assignRole($outsider, 'auditor', null));
    }

    public function test_company_wide_role_cannot_be_assigned_twice(): void
    {
        $user = $this->makeMember($this->company, ['auditor' => null]);

        $this->expectException(QueryException::class);
        $this->tenant()->runAs($this->company, fn () => $this->assignRole($user, 'auditor', null));
    }
}
