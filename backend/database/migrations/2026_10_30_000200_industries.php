<?php

use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * DivaWaste beyond textile (the owner, 2026-10-30: "make him choose the industry, not only textile, add other things
 * that have manageable waste, agriculture for example"). Zone types, waste families and catalogue items say which
 * industries they belong to (null = every industry); a company sees those of its own (CompanyModules::industry).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['zone_types', 'waste_families', 'waste_catalog_items'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->json('industries')->nullable()->after('name');
            });
        }
        // A database that already holds the reference data gets the industries' rows now; a new one gets them with its seed.
        if (DB::table('waste_families')->exists() && DB::table('units')->where('code', 'kg')->exists()) {
            (new ReferenceDataSeeder)->industries();
        }
    }

    public function down(): void
    {
        foreach (['zone_types', 'waste_families', 'waste_catalog_items'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn('industries');
            });
        }
    }
};
