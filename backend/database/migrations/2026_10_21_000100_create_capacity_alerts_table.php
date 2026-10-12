<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The last state people were told about for each physical limit: the room and the load of a zone, the weight of a site.
 * The occupancy itself is still never stored — it is counted from the lots. This only remembers "warning",
 * "unverifiable" or "exceeded" so that the same unchanged state is not announced at every stock movement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('capacity_alerts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('site_id');
            // Null for the limit of the site itself.
            $table->unsignedBigInteger('zone_id')->nullable();
            $table->enum('capacity_type', ['space', 'load', 'site_load']);
            $table->enum('alert_level', ['warning', 'unverifiable', 'exceeded'])->nullable();
            $table->dateTime('alerted_at')->nullable();
            $table->dateTime('last_evaluated_at')->nullable();
            $table->datetimes();

            $table->foreign(['company_id', 'site_id'], 'fk_capacity_alerts_site')->references(['company_id', 'id'])->on('sites')->cascadeOnDelete();
            $table->foreign(['company_id', 'zone_id'], 'fk_capacity_alerts_zone')->references(['company_id', 'id'])->on('zones')->cascadeOnDelete();
            $table->index(['site_id', 'zone_id', 'capacity_type'], 'ix_capacity_alerts_subject');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('capacity_alerts');
    }
};
