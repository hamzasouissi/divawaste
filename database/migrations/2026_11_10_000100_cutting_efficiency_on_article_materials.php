<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The efficiency of the placement a fabric of an article is cut from (marker efficiency, %): what of the fabric
 * consumed ends in the pieces. The rest is the expected cutting waste. Empty: the company's default applies.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('article_materials', function (Blueprint $table) {
            $table->decimal('efficiency_pct', 5, 2)->nullable()->after('quantity_per_piece');
        });
    }

    public function down(): void
    {
        Schema::table('article_materials', function (Blueprint $table) {
            $table->dropColumn('efficiency_pct');
        });
    }
};
