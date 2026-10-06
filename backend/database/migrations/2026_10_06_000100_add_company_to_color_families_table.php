<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('color_families', function (Blueprint $table) {
            // NULL = default colour of the platform, visible to everyone. Otherwise a colour added by that company,
            // visible to it alone. Codes stay unique across both, so a lot or a waste type keeps naming its colour by code.
            $table->foreignId('company_id')->nullable()->after('id')->constrained('companies', 'id', 'fk_color_families_company')->restrictOnDelete();
            $table->index(['company_id', 'is_active'], 'ix_color_families_company');
        });
    }

    public function down(): void
    {
        Schema::table('color_families', function (Blueprint $table) {
            $table->dropForeign('fk_color_families_company');
            $table->dropIndex('ix_color_families_company');
            $table->dropColumn('company_id');
        });
    }
};
