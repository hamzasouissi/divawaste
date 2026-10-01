<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->publicUlid();
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('email', 191)->unique('uq_users_email');
            $table->dateTime('email_verified_at')->nullable();
            $table->string('phone', 30)->nullable();
            $table->dateTime('phone_verified_at')->nullable();
            $table->string('password');
            $table->dateTime('password_changed_at')->nullable();
            $table->rememberToken();
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->dateTime('two_factor_confirmed_at')->nullable();
            $table->unsignedTinyInteger('failed_login_attempts')->default(0);
            $table->dateTime('locked_until')->nullable();
            $table->string('locale', 5)->default('fr');
            $table->string('timezone', 64)->nullable();
            // FK to companies added in the tenancy batch (cycle users ↔ companies).
            $table->unsignedBigInteger('last_company_id')->nullable();
            $table->dateTime('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
            $table->enum('status', ['active', 'disabled', 'anonymized'])->default('active');
            $table->dateTime('anonymized_at')->nullable();
            $table->datetimes();

            $table->index('status', 'ix_users_status');
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email', 191)->primary();
            $table->string('token');
            $table->dateTime('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
