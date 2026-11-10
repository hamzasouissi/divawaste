<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The waste a raw material leaves when it is used (a fabric: its cutting scraps). Empty: a fabric leaves the company's
 * "Chutes de coupe", anything else says nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('raw_materials', function (Blueprint $table) {
            $table->unsignedBigInteger('waste_type_id')->nullable()->after('unit_id');
            $table->foreign(['company_id', 'waste_type_id'], 'fk_raw_materials_waste_type')->references(['company_id', 'id'])->on('waste_types')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('raw_materials', function (Blueprint $table) {
            $table->dropForeign('fk_raw_materials_waste_type');
            $table->dropColumn('waste_type_id');
        });
    }
};
