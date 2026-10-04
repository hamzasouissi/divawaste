<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('recipient', 191);
            $table->enum('channel', ['mail', 'database', 'whatsapp']);
            $table->string('notification_type', 100);
            $table->string('subject', 255)->nullable();
            $table->string('related_type', 50)->nullable();
            $table->unsignedBigInteger('related_id')->nullable();
            $table->enum('status', ['queued', 'sent', 'failed', 'bounced'])->default('queued');
            $table->string('provider_message_id', 191)->nullable();
            $table->string('error_message', 1000)->nullable();
            $table->dateTime('queued_at');
            $table->dateTime('sent_at')->nullable();
            $table->datetimes();

            $table->index(['company_id', 'related_type', 'related_id'], 'ix_nl_related');
            $table->index('provider_message_id', 'ix_nl_provider_msg');
            $table->index('created_at', 'ix_nl_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
    }
};
