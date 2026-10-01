<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Authorization\PermissionCatalog;
use App\Modules\Identity\Enums\Audience;
use App\Modules\Identity\Models\Permission;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthorizationSeeder::class);
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(AuthorizationSeeder::class);

        $this->assertSame(count(PermissionCatalog::permissions()), Permission::query()->count());
        $this->assertSame(9, Role::query()->whereNull('company_id')->where('is_system', true)->count());
    }

    public function test_seeding_preserves_runtime_matrix_changes(): void
    {
        $role = Role::findByName('environment_manager');
        $role->revokePermissionTo('lots.delete');

        $this->seed(AuthorizationSeeder::class);

        $this->assertFalse(Role::findByName('environment_manager')->hasPermissionTo('lots.delete'));
    }

    public function test_system_roles_only_receive_permissions_of_their_audience(): void
    {
        foreach (Role::query()->with('permissions')->get() as $role) {
            foreach ($role->permissions as $permission) {
                $this->assertContains(
                    $permission->audience,
                    [$role->audience, Audience::Any],
                    "{$role->name} must not hold {$permission->name}",
                );
            }
        }
    }

    public function test_platform_staff_role_is_stored_with_morph_alias(): void
    {
        $user = User::factory()->create();
        $user->assignRole('super_admin');

        $this->assertSame('user', DB::table('model_has_roles')->where('model_id', $user->id)->value('model_type'));
        $this->assertTrue($user->hasPermissionTo('platform.companies.review'));
        $this->assertFalse($user->hasPermissionTo('lots.create'));
    }

    public function test_workshop_operator_is_mobile_only(): void
    {
        $role = Role::findByName('workshop_operator');

        $this->assertTrue($role->hasPermissionTo('app.mobile.access'));
        $this->assertFalse($role->hasPermissionTo('app.web.access'));
    }

    public function test_role_names_are_unique_per_company_scope(): void
    {
        $base = ['guard_name' => 'web', 'label' => '{}', 'audience' => 'industrial', 'created_at' => now(), 'updated_at' => now()];

        DB::table('roles')->insert(['name' => 'quality', 'company_id' => 1] + $base);
        DB::table('roles')->insert(['name' => 'quality', 'company_id' => 2] + $base);

        $this->expectException(QueryException::class);
        DB::table('roles')->insert(['name' => 'quality', 'company_id' => 1] + $base);
    }

    public function test_two_system_roles_cannot_share_a_name(): void
    {
        $this->expectException(QueryException::class);

        DB::table('roles')->insert([
            'name' => 'client_admin', 'guard_name' => 'web', 'label' => '{}', 'audience' => 'industrial',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
