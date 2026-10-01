<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_role_assignments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('user_id');
            $table->foreignId('role_id')->constrained('roles', 'id', 'fk_ura_role')->restrictOnDelete();
            // NULL = all sites of the company, including future ones.
            $table->unsignedBigInteger('site_id')->nullable();
            $table->unsignedBigInteger('site_scope_key')->storedAs('IFNULL(site_id, 0)');
            $table->foreignId('granted_by_user_id')->nullable()->constrained('users', 'id', 'fk_ura_granted_by')->restrictOnDelete();
            $table->datetimes();

            // Membership guaranteed by the FK.
            $table->foreign(['company_id', 'user_id'], 'fk_ura_membership')->references(['company_id', 'user_id'])->on('company_users')->restrictOnDelete();
            $table->foreign(['company_id', 'site_id'], 'fk_ura_site')->references(['company_id', 'id'])->on('sites')->restrictOnDelete();
            $table->unique(['company_id', 'user_id', 'role_id', 'site_scope_key'], 'uq_ura_assignment');
            $table->index(['company_id', 'site_id'], 'ix_ura_company_site');
        });

        Schema::create('user_invitations', function (Blueprint $table) {
            $table->id();
            $table->publicUlid();
            $table->foreignId('company_id')->constrained('companies', 'id', 'fk_user_invitations_company')->restrictOnDelete();
            $table->string('email', 191);
            $table->string('first_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            $table->foreignId('role_id')->constrained('roles', 'id', 'fk_user_invitations_role')->restrictOnDelete();
            // Simplified MVP: site ULIDs list (replaces user_invitation_sites); empty = all sites.
            $table->json('site_ids')->nullable();
            $table->char('token_hash', 64)->unique('uq_user_invitations_token');
            $table->enum('status', ['pending', 'accepted', 'revoked', 'expired'])->default('pending');
            // 1 while pending, NULL otherwise: one pending invitation per email and company.
            $table->boolean('pending_flag')->nullable()->default(true);
            $table->string('message', 1000)->nullable();
            $table->foreignId('invited_by_user_id')->constrained('users', 'id', 'fk_user_invitations_invited_by')->restrictOnDelete();
            $table->dateTime('expires_at');
            $table->dateTime('last_sent_at');
            $table->unsignedTinyInteger('send_count')->default(1);
            $table->dateTime('accepted_at')->nullable();
            $table->foreignId('accepted_user_id')->nullable()->constrained('users', 'id', 'fk_user_invitations_accepted_user')->restrictOnDelete();
            $table->dateTime('revoked_at')->nullable();
            $table->foreignId('revoked_by_user_id')->nullable()->constrained('users', 'id', 'fk_user_invitations_revoked_by')->restrictOnDelete();
            $table->datetimes();

            $table->unique(['company_id', 'id'], 'uq_user_invitations_company_id_id');
            $table->unique(['company_id', 'email', 'pending_flag'], 'uq_user_invitations_pending');
            $table->index(['status', 'expires_at'], 'ix_user_invitations_status_expires');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_invitations');
        Schema::dropIfExists('user_role_assignments');
    }
};
