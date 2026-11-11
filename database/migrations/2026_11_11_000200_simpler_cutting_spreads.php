<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A spread (matelas) is what the cutting room measures: a fabric, the length of the spread, its layers (couches)
 * planned then done, and the efficiency of its placement. The sizes stay on the OF: the cutting waste depends on the
 * fabric laid, not on the sizes cut in it.
 *
 *  - the length the user measures already holds the ends of each layer: the allowance joins the length;
 *  - the laize and the grammage of the rolls really laid may differ from the raw material's: a spread may say its own;
 *  - the pieces of each size per layer, the received lot and the "recorded as consumption" mark go.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('cutting_spreads')->whereNotNull('end_allowance_cm')
            ->update(['marker_length_m' => DB::raw('marker_length_m + end_allowance_cm / 100')]);

        Schema::table('cutting_spreads', function (Blueprint $table) {
            $table->dropForeign('fk_cutting_spreads_batch');
        });
        Schema::table('cutting_spreads', function (Blueprint $table) {
            $table->renameColumn('marker_length_m', 'length_m');
            $table->dropColumn(['raw_material_batch_id', 'end_allowance_cm', 'ratio', 'consumption_recorded_at']);
            $table->decimal('width_cm', 6, 2)->nullable()->after('efficiency_pct');      // null: the raw material's
            $table->decimal('grammage_gsm', 7, 2)->nullable()->after('width_cm');        // null: the raw material's
        });
    }

    public function down(): void
    {
        Schema::table('cutting_spreads', function (Blueprint $table) {
            $table->dropColumn(['width_cm', 'grammage_gsm']);
            $table->renameColumn('length_m', 'marker_length_m');
            $table->unsignedBigInteger('raw_material_batch_id')->nullable()->after('raw_material_id');
            $table->decimal('end_allowance_cm', 6, 2)->nullable()->after('efficiency_pct');
            $table->json('ratio')->nullable()->after('end_allowance_cm');
            $table->dateTime('consumption_recorded_at')->nullable()->after('notes');
            $table->foreign(['company_id', 'raw_material_batch_id'], 'fk_cutting_spreads_batch')->references(['company_id', 'id'])->on('raw_material_batches')->restrictOnDelete();
        });
    }
};
