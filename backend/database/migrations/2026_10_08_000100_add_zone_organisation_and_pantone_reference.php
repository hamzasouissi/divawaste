<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A zone no longer has to be of a given type.
        Schema::table('zones', function (Blueprint $table) {
            $table->unsignedBigInteger('zone_type_id')->nullable()->change();
        });

        // Capacity as people know it: "this zone holds 40 bales, or 100 bags".
        Schema::create('zone_capacities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('zone_id');
            $table->foreignId('packaging_type_id')->constrained('packaging_types', 'id', 'fk_zone_capacities_packaging')->restrictOnDelete();
            $table->unsignedInteger('max_count');
            $table->datetimes();

            $table->foreign(['company_id', 'zone_id'], 'fk_zone_capacities_zone')->references(['company_id', 'id'])->on('zones')->cascadeOnDelete();
            $table->unique(['zone_id', 'packaging_type_id'], 'uq_zone_capacities_zone_packaging');
        });

        // Optional: what a zone is meant to hold. An empty list means "anything".
        Schema::create('zone_accepted_waste_types', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('zone_id');
            $table->unsignedBigInteger('waste_type_id');
            $table->datetimes();

            $table->foreign(['company_id', 'zone_id'], 'fk_zawt_zone')->references(['company_id', 'id'])->on('zones')->cascadeOnDelete();
            $table->foreign(['company_id', 'waste_type_id'], 'fk_zawt_waste_type')->references(['company_id', 'id'])->on('waste_types')->restrictOnDelete();
            $table->unique(['zone_id', 'waste_type_id'], 'uq_zawt_zone_waste_type');
        });

        Schema::create('zone_accepted_colors', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('zone_id');
            $table->foreignId('color_family_id')->constrained('color_families', 'id', 'fk_zac_color')->restrictOnDelete();
            $table->datetimes();

            $table->foreign(['company_id', 'zone_id'], 'fk_zac_zone')->references(['company_id', 'id'])->on('zones')->cascadeOnDelete();
            $table->unique(['zone_id', 'color_family_id'], 'uq_zac_zone_color');
        });

        // The reference is typed by the company: the Pantone library itself is not part of the application.
        Schema::table('color_families', function (Blueprint $table) {
            $table->string('pantone_reference', 40)->nullable()->after('hex_color');
        });
    }

    public function down(): void
    {
        Schema::table('color_families', fn (Blueprint $table) => $table->dropColumn('pantone_reference'));
        foreach (['zone_accepted_colors', 'zone_accepted_waste_types', 'zone_capacities'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
