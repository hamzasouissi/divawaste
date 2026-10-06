<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('user_id');
            $table->string('notification_type', 100);
            $table->enum('channel', ['mail', 'database', 'whatsapp']);
            $table->boolean('is_enabled')->default(true);
            $table->datetimes();

            $table->unique(['company_id', 'user_id', 'notification_type', 'channel'], 'uq_np_pref');
            $table->foreign(['company_id', 'user_id'], 'fk_np_membership')
                ->references(['company_id', 'user_id'])
                ->on('company_users')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};
