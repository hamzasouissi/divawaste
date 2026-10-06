<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->publicUlid();
            $table->enum('company_type', ['industrial', 'provider', 'brand']);
            $table->enum('status', ['pending_verification', 'pending_approval', 'active', 'rejected', 'suspended', 'closed'])->default('pending_verification');
            $table->string('legal_name', 191);
            $table->string('trade_name', 191)->nullable();
            $table->string('tax_id', 50)->nullable();
            $table->string('vat_number', 50)->nullable();
            $table->string('trade_register_number', 50)->nullable();
            $table->foreignId('country_id')->constrained('countries', 'id', 'fk_companies_country')->restrictOnDelete();
            $table->char('currency_code', 3);
            $table->string('default_locale', 5)->default('fr');
            $table->string('timezone', 64);
            $table->string('address_line1', 191)->nullable();
            $table->string('address_line2', 191)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('region', 100)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email', 191)->nullable();
            $table->string('billing_email', 191)->nullable();
            $table->string('website', 191)->nullable();
            $table->foreignId('primary_contact_user_id')->nullable()->constrained('users', 'id', 'fk_companies_primary_contact')->nullOnDelete();
            // FK to stored_files added with that table (cycle companies ↔ stored_files).
            $table->unsignedBigInteger('logo_stored_file_id')->nullable();
            $table->json('settings')->nullable();
            $table->json('onboarding_state')->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users', 'id', 'fk_companies_approved_by')->restrictOnDelete();
            $table->dateTime('rejected_at')->nullable();
            $table->foreignId('rejected_by_user_id')->nullable()->constrained('users', 'id', 'fk_companies_rejected_by')->restrictOnDelete();
            $table->string('rejection_reason', 1000)->nullable();
            $table->dateTime('suspended_at')->nullable();
            $table->foreignId('suspended_by_user_id')->nullable()->constrained('users', 'id', 'fk_companies_suspended_by')->restrictOnDelete();
            $table->string('suspension_reason', 1000)->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->dateTime('data_erasure_requested_at')->nullable();
            $table->dateTime('anonymized_at')->nullable();
            $table->datetimes();

            $table->foreign('currency_code', 'fk_companies_currency')->references('code')->on('currencies')->restrictOnDelete();
            $table->unique(['country_id', 'tax_id'], 'uq_companies_country_tax_id');
            $table->index(['company_type', 'status'], 'ix_companies_type_status');
            $table->index(['status', 'submitted_at'], 'ix_companies_status_submitted');
        });

        // Deferred FKs from Batch 02.
        Schema::table('users', function (Blueprint $table) {
            $table->foreign('last_company_id', 'fk_users_last_company')->references('id')->on('companies')->nullOnDelete();
        });
        Schema::table('roles', function (Blueprint $table) {
            $table->foreign('company_id', 'fk_roles_company')->references('id')->on('companies')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('roles', fn (Blueprint $table) => $table->dropForeign('fk_roles_company'));
        Schema::table('users', fn (Blueprint $table) => $table->dropForeign('fk_users_last_company'));
        Schema::dropIfExists('companies');
    }
};
