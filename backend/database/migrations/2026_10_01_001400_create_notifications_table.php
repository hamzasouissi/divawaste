<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->string('type');
            $table->string('notifiable_type');
            $table->unsignedBigInteger('notifiable_id');
            $table->unsignedBigInteger('company_id')->nullable();
            $table->json('data');
            $table->dateTime('read_at')->nullable();
            $table->timestamps();

            $table->index(['notifiable_type', 'notifiable_id', 'company_id', 'read_at'], 'ix_notifications_inbox');
            $table->index('created_at', 'ix_notifications_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
