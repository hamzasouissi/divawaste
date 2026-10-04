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
    /** The seven roles of the cahier (§2), as users read them. */
    private const ROLE_LABELS = [
        'super_admin' => ['fr' => 'Administrateur plateforme', 'en' => 'Platform administrator'],
        'platform_support' => ['fr' => 'Support plateforme', 'en' => 'Platform support'],
        'client_admin' => ['fr' => 'Administrateur client', 'en' => 'Client administrator'],
        'environment_manager' => ['fr' => 'Responsable environnement', 'en' => 'Environment manager'],
        'workshop_operator' => ['fr' => 'Opérateur atelier', 'en' => 'Workshop operator'],
        'management_viewer' => ['fr' => 'Direction (lecture)', 'en' => 'Management (read-only)'],
        'auditor' => ['fr' => 'Auditeur', 'en' => 'Auditor'],
        'provider_admin' => ['fr' => 'Administrateur prestataire', 'en' => 'Provider administrator'],
        'provider_operator' => ['fr' => 'Opérateur prestataire', 'en' => 'Provider operator'],
    ];

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
                        'label' => self::ROLE_LABELS[$name] ?? ['fr' => $name, 'en' => $name],
                        'audience' => $audience,
                        'is_system' => true,
                    ]);
                    $role->syncPermissions($permissions);
                } elseif (isset(self::ROLE_LABELS[$name])) {
                    // Labels are wording, not rights: they follow the code on every run (the matrix does not).
                    $role->forceFill(['label' => self::ROLE_LABELS[$name]])->save();
                }
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
