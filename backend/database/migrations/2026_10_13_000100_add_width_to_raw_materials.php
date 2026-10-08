<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The usable width of a fabric (laize). With its weight per m², metres consumed become kilograms, and the
        // waste of an order can be read as a share of what it consumed.
        Schema::table('raw_materials', function (Blueprint $table) {
            $table->decimal('width_cm', 7, 2)->nullable()->after('grammage_gsm');
        });
    }

    public function down(): void
    {
        Schema::table('raw_materials', fn (Blueprint $table) => $table->dropColumn('width_cm'));
    }
};
