<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An article no longer says the efficiency of its fabrics: the efficiency belongs to the placement of each spread
 * (matelas) of an OF. Before the spreads, an article's cutting waste is estimated at the company's usual efficiency.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('article_materials', function (Blueprint $table) {
            $table->dropColumn('efficiency_pct');
        });
    }

    public function down(): void
    {
        Schema::table('article_materials', function (Blueprint $table) {
            $table->decimal('efficiency_pct', 5, 2)->nullable()->after('quantity_per_piece');
        });
    }
};
