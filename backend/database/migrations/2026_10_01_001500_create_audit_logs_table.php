<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->dateTime('created_at', 3);
            $table->unsignedBigInteger('company_id')->nullable();
            $table->enum('actor_type', ['user', 'system', 'api_client', 'scheduler']);
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->unsignedBigInteger('actor_company_id')->nullable();
            $table->unsignedBigInteger('impersonator_user_id')->nullable();
            $table->string('event', 40);
            $table->string('auditable_type', 50);
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->char('auditable_ulid', 26)->nullable();
            $table->string('parent_type', 50)->nullable();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->enum('source', ['web', 'mobile', 'provider_portal', 'api', 'sync', 'system', 'console']);
            $table->unsignedBigInteger('device_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('url', 500)->nullable();
            $table->string('http_method', 10)->nullable();
            $table->json('tags')->nullable();

            $table->primary(['id', 'created_at']);
            $table->index(['company_id', 'created_at'], 'ix_audit_company_created');
            $table->index(['auditable_type', 'auditable_id', 'created_at'], 'ix_audit_entity');
            $table->index(['parent_type', 'parent_id', 'created_at'], 'ix_audit_parent');
            $table->index(['actor_user_id', 'created_at'], 'ix_audit_actor');
            $table->index('request_id', 'ix_audit_request');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
