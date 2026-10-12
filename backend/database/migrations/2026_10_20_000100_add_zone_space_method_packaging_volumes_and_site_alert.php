<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Physical capacity of zones and sites.
 *
 * The room of a zone is measured one way or the other, never both: in packages per packaging ("100 bags or 50 boxes",
 * as before) or in volume, against `capacity_m3`. Every existing zone keeps the packaging way, so no figure moves; a
 * volume recorded on a zone earlier is not turned into a limit by this migration. Volume needs to know how much room
 * one package takes: that is a fact of the company, kept per packaging in `packaging_volumes`.
 *
 * The weight a site may hold (`sites.capacity_kg`) becomes a limit that is watched, with its own alert percentage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('zones', function (Blueprint $table) {
            $table->enum('space_method', ['packaging', 'volume'])->default('packaging')->after('capacity_m3');
        });
        Schema::table('sites', function (Blueprint $table) {
            $table->unsignedTinyInteger('capacity_alert_pct')->default(90)->after('capacity_kg');
        });
        Schema::create('packaging_volumes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies', 'id', 'fk_packaging_volumes_company')->cascadeOnDelete();
            $table->foreignId('packaging_type_id')->constrained('packaging_types', 'id', 'fk_packaging_volumes_packaging')->restrictOnDelete();
            // The room one package takes once stored, in cubic metres.
            $table->decimal('volume_m3', 10, 4);
            $table->datetimes();

            $table->unique(['company_id', 'packaging_type_id'], 'uq_packaging_volumes_company_packaging');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('packaging_volumes');
        Schema::table('sites', fn (Blueprint $table) => $table->dropColumn('capacity_alert_pct'));
        Schema::table('zones', fn (Blueprint $table) => $table->dropColumn('space_method'));
    }
};
