<?php

namespace Database\Seeders;

use App\Modules\Identity\Authorization\PermissionCatalog;
use App\Modules\Identity\Models\Permission;
use App\Modules\Identity\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds the permission catalog and the system roles. Idempotent.
 * Existing system-role matrices are only filled on first creation so runtime edits are preserved.
 */
class AuthorizationSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $order = 0;
            foreach (PermissionCatalog::permissions() as $name => [$scope, $audience]) {
                Permission::query()->updateOrCreate(
                    ['name' => $name, 'guard_name' => 'web'],
                    [
                        'module' => strtok($name, '.'),
                        'scope_level' => $scope,
                        'audience' => $audience,
                        'label' => ['fr' => $name, 'en' => $name],
                        'sort_order' => $order += 10,
                    ],
                );
            }

            foreach (PermissionCatalog::systemRoles() as $name => [$audience, $permissions]) {
                $role = Role::query()->whereNull('company_id')->where('name', $name)->where('guard_name', 'web')->first();

                if ($role === null) {
                    $role = Role::query()->create([
                        'name' => $name,
                        'guard_name' => 'web',
                        'label' => ['fr' => $name, 'en' => $name],
                        'audience' => $audience,
                        'is_system' => true,
                    ]);
                    $role->syncPermissions($permissions);
                }
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
