<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spatie permission tables customized per blueprint §15.3.
 * model_has_roles is used for platform staff only; tenant roles go through user_role_assignments.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 125);
            $table->string('guard_name', 125)->default('web');
            $table->string('module', 40);
            $table->enum('scope_level', ['platform', 'company', 'site']);
            $table->enum('audience', ['platform', 'industrial', 'provider', 'brand', 'any']);
            $table->json('label');
            $table->json('description')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->datetimes();

            $table->unique(['name', 'guard_name'], 'uq_permissions_name_guard');
            $table->index('module', 'ix_permissions_module');
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            // NULL = system role. FK to companies added in the tenancy batch.
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('company_scope_key')->storedAs('IFNULL(company_id, 0)');
            $table->string('name', 125);
            $table->string('guard_name', 125)->default('web');
            $table->json('label');
            $table->json('description')->nullable();
            $table->enum('audience', ['platform', 'industrial', 'provider', 'brand']);
            $table->boolean('is_system')->default(false);
            $table->foreignId('cloned_from_role_id')->nullable()->constrained('roles', 'id', 'fk_roles_cloned_from')->nullOnDelete();
            $table->datetimes();

            $table->unique(['company_scope_key', 'name', 'guard_name'], 'uq_roles_scope_name_guard');
            $table->index('company_id', 'ix_roles_company');
        });

        Schema::create('role_has_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('permission_id');
            $table->unsignedBigInteger('role_id');

            $table->primary(['permission_id', 'role_id']);
            $table->foreign('permission_id', 'fk_rhp_permission')->references('id')->on('permissions')->cascadeOnDelete();
            $table->foreign('role_id', 'fk_rhp_role')->references('id')->on('roles')->cascadeOnDelete();
            $table->index('role_id', 'ix_rhp_role');
        });

        Schema::create('model_has_roles', function (Blueprint $table) {
            $table->unsignedBigInteger('role_id');
            $table->string('model_type', 191);
            $table->unsignedBigInteger('model_id');

            $table->primary(['role_id', 'model_id', 'model_type']);
            $table->foreign('role_id', 'fk_mhr_role')->references('id')->on('roles')->cascadeOnDelete();
            $table->index(['model_id', 'model_type'], 'ix_mhr_model');
        });

        Schema::create('model_has_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('permission_id');
            $table->string('model_type', 191);
            $table->unsignedBigInteger('model_id');

            $table->primary(['permission_id', 'model_id', 'model_type']);
            $table->foreign('permission_id', 'fk_mhp_permission')->references('id')->on('permissions')->cascadeOnDelete();
            $table->index(['model_id', 'model_type'], 'ix_mhp_model');
        });
    }

    public function down(): void
    {
        foreach (['model_has_permissions', 'model_has_roles', 'role_has_permissions', 'roles', 'permissions'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
