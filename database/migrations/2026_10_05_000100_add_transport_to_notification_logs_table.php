<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_logs', function (Blueprint $table) {
            // The mail transport the message went through ("smtp", "log"…): "sent" through "log" never left the server.
            $table->string('transport', 30)->nullable()->after('channel');
        });
    }

    public function down(): void
    {
        Schema::table('notification_logs', function (Blueprint $table) {
            $table->dropColumn('transport');
        });
    }
};
