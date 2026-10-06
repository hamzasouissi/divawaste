<?php

namespace Tests\Concerns;

use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\UserRoleAssignment;
use App\Modules\Sites\Models\Site;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Models\CompanyUser;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Requires ReferenceDataSeeder + AuthorizationSeeder.
 */
trait InteractsWithTenants
{
    protected function tenant(): TenantContext
    {
        return app(TenantContext::class);
    }

    protected function makeCompany(string $type = 'industrial'): Company
    {
        return Company::factory()->active()->create(['company_type' => $type]);
    }

    protected function makeSite(Company $company, string $code): Site
    {
        return $this->tenant()->runAs($company, fn () => Site::query()->create([
            'code' => $code,
            'name' => "Site {$code}",
            'country_id' => DB::table('countries')->where('iso2', 'TN')->value('id'),
        ]));
    }

    /**
     * @param  array<string, Site|null>  $roles  role name => site (null = all sites)
     */
    protected function makeMember(Company $company, array $roles = []): User
    {
        $user = User::factory()->create();

        $this->tenant()->runAs($company, function () use ($user, $roles) {
            CompanyUser::query()->create(['user_id' => $user->id, 'joined_at' => now()]);
            foreach ($roles as $role => $site) {
                $this->assignRole($user, $role, $site);
            }
        });

        return $user->refresh();
    }

    protected function assignRole(User $user, string $role, ?Site $site): UserRoleAssignment
    {
        return UserRoleAssignment::query()->create([
            'user_id' => $user->id,
            'role_id' => Role::query()->whereNull('company_id')->where('name', $role)->value('id'),
            'site_id' => $site?->id,
        ]);
    }

    protected function tokenFor(User $user, Company $company): string
    {
        $token = $user->createToken('test');
        $token->accessToken->forceFill(['company_id' => $company->id])->save();

        // The auth guard caches the user between requests of one test: switching tokens must reset it.
        app('auth')->forgetGuards();

        return $token->plainTextToken;
    }
}
