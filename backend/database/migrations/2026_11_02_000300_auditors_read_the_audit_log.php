<?php

use App\Modules\Identity\Models\Permission;
use App\Modules\Identity\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\PermissionRegistrar;

/**
 * The auditor's role says it reads the audit log, but its default matrix did not hold audit.view (found in the review of
 * every role, 2026-11-02). The seeder only adds permissions that are new to existing roles: the standard auditor role
 * receives this one here.
 */
return new class extends Migration
{
    public function up(): void
    {
        $role = Role::query()->whereNull('company_id')->where('name', 'auditor')->where('guard_name', 'web')->first();
        $permission = Permission::query()->where('name', 'audit.view')->where('guard_name', 'web')->first();
        if ($role !== null && $permission !== null) {
            $role->givePermissionTo($permission);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    public function down(): void
    {
        Role::query()->whereNull('company_id')->where('name', 'auditor')->where('guard_name', 'web')->first()?->revokePermissionTo('audit.view');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
