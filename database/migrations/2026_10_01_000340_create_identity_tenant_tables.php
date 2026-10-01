<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('tokenable_type', 191);
            $table->unsignedBigInteger('tokenable_id');
            $table->string('name', 191);
            $table->char('token', 64)->unique('uq_pat_token');
            $table->text('abilities')->nullable();
            // Tenant the token is bound to (binding, not a scope). device FK added with devices.
            $table->foreignId('company_id')->nullable()->constrained('companies', 'id', 'fk_pat_company')->restrictOnDelete();
            $table->unsignedBigInteger('device_id')->nullable();
            $table->dateTime('last_used_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->datetimes();

            $table->index(['tokenable_type', 'tokenable_id'], 'ix_pat_tokenable');
            $table->index('device_id', 'ix_pat_device');
            $table->index('expires_at', 'ix_pat_expires');
        });

        Schema::create('legal_acceptances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users', 'id', 'fk_legal_acceptances_user')->restrictOnDelete();
            $table->foreignId('legal_document_id')->constrained('legal_documents', 'id', 'fk_legal_acceptances_document')->restrictOnDelete();
            $table->foreignId('company_id')->nullable()->constrained('companies', 'id', 'fk_legal_acceptances_company')->restrictOnDelete();
            $table->enum('context', ['registration', 'invitation', 'reacceptance', 'provider_registration']);
            $table->dateTime('accepted_at');
            $table->string('ip_address', 45);
            $table->string('user_agent', 512)->nullable();
            $table->dateTime('created_at');

            $table->index(['user_id', 'legal_document_id'], 'ix_legal_acceptances_user_document');
        });

        Schema::create('company_textile_activities', function (Blueprint $table) {
            $table->foreignId('company_id')->constrained('companies', 'id', 'fk_cta_company')->restrictOnDelete();
            $table->foreignId('textile_activity_id')->constrained('textile_activities', 'id', 'fk_cta_activity')->restrictOnDelete();
            $table->dateTime('created_at');

            $table->primary(['company_id', 'textile_activity_id']);
        });

        Schema::create('company_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies', 'id', 'fk_company_users_company')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users', 'id', 'fk_company_users_user')->restrictOnDelete();
            $table->enum('status', ['active', 'suspended', 'removed'])->default('active');
            $table->string('job_title', 100)->nullable();
            $table->foreignId('invited_by_user_id')->nullable()->constrained('users', 'id', 'fk_company_users_invited_by')->restrictOnDelete();
            $table->dateTime('joined_at');
            $table->dateTime('suspended_at')->nullable();
            $table->dateTime('removed_at')->nullable();
            $table->datetimes();

            // Also the composite-FK target for user_role_assignments.
            $table->unique(['company_id', 'user_id'], 'uq_company_users_company_user');
            $table->index(['user_id', 'status'], 'ix_company_users_user_status');
        });
    }

    public function down(): void
    {
        foreach (['company_users', 'company_textile_activities', 'legal_acceptances', 'personal_access_tokens'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
