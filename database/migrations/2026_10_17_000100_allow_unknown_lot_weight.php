<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A weight nobody measured is unknown, not zero. Lots may be registered before they are weighed, and the 0 kg that
 * stood for "unknown" on counted lots becomes an empty weight. Nothing else is rewritten: a lot whose 0 kg was typed
 * as a weight keeps it, and documents already issued keep their figures.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('waste_lots', function (Blueprint $table) {
            $table->decimal('net_weight_kg', 12, 3)->nullable()->change();
        });
        DB::statement("ALTER TABLE waste_lots MODIFY weight_basis ENUM('measured', 'computed', 'estimated', 'unknown') NOT NULL DEFAULT 'measured'");

        Schema::table('pickup_lots', function (Blueprint $table) {
            $table->decimal('declared_weight_kg', 12, 3)->nullable()->change();
            // Where the weight the line starts with comes from; null on lines recorded before this was kept.
            $table->enum('weight_basis', ['measured', 'computed', 'estimated', 'unknown'])->nullable()->after('declared_weight_kg');
        });

        // The placeholder becomes what it meant. Only lines still carrying it untouched follow: a weight typed at
        // loading or at reception is a measure and stays.
        $unknown = DB::table('waste_lots')->where('weight_basis', 'unknown')->where('net_weight_kg', 0);
        DB::table('pickup_lots')->whereIn('waste_lot_id', (clone $unknown)->select('id'))->where('measure_dimension', '!=', 'mass')
            ->where('declared_weight_kg', 0)->update(['declared_weight_kg' => null, 'weight_basis' => 'unknown']);
        DB::table('pickup_lots')->whereIn('waste_lot_id', (clone $unknown)->select('id'))->where('measure_dimension', '!=', 'mass')
            ->where('departure_weight_kg', 0)->update(['departure_weight_kg' => null]);
        $unknown->update(['net_weight_kg' => null]);
    }

    public function down(): void
    {
        DB::table('waste_lots')->where('weight_basis', 'estimated')->update(['weight_basis' => 'measured']);
        DB::table('waste_lots')->whereNull('net_weight_kg')->update(['net_weight_kg' => 0, 'weight_basis' => 'unknown']);
        DB::table('pickup_lots')->whereNull('declared_weight_kg')->update(['declared_weight_kg' => 0]);
        Schema::table('pickup_lots', function (Blueprint $table) {
            $table->dropColumn('weight_basis');
            $table->decimal('declared_weight_kg', 12, 3)->nullable(false)->change();
        });
        DB::statement("ALTER TABLE waste_lots MODIFY weight_basis ENUM('measured', 'computed', 'unknown') NOT NULL DEFAULT 'measured'");
        Schema::table('waste_lots', function (Blueprint $table) {
            $table->decimal('net_weight_kg', 12, 3)->nullable(false)->change();
        });
    }
};
