<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Raw materials counted by area (foam sheets, adhesive films, leather: m², ft², in², cm²) or in litres (solutions,
 * glues), as an export plant's bill of materials counts them; and the price of a batch to the sixth decimal (a label
 * at 0.01751 $ the piece, a fabric at 9.93881 $ the yard is 10.869215 $ the metre). Reference data, added where it is
 * missing (ReferenceDataSeeder lists the units too).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE units MODIFY dimension ENUM('mass', 'volume', 'count', 'length', 'area') NOT NULL");
        foreach ([['m2', 'Mètre carré', 'Square metre', 'm²', '1', true], ['ft2', 'Pied carré', 'Square foot', 'ft²', '0.09290304', false],
            ['in2', 'Pouce carré', 'Square inch', 'in²', '0.00064516', false], ['cm2', 'Centimètre carré', 'Square centimetre', 'cm²', '0.0001', false]] as [$code, $fr, $en, $symbol, $factor, $base]) {
            DB::table('units')->insertOrIgnore(['code' => $code, 'name' => json_encode(['fr' => $fr, 'en' => $en], JSON_UNESCAPED_UNICODE), 'symbol' => $symbol,
                'dimension' => 'area', 'factor_to_base' => $factor, 'is_base' => $base, 'created_at' => now(), 'updated_at' => now()]);
        }

        Schema::table('raw_material_batches', function (Blueprint $table) {
            $table->decimal('unit_price', 16, 6)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('raw_material_batches', function (Blueprint $table) {
            $table->decimal('unit_price', 14, 4)->nullable()->change();
        });
        // Only the units nothing is counted in: a unit in use stays.
        $area = DB::table('units')->where('dimension', 'area')->pluck('id');
        $used = DB::table('raw_materials')->whereIn('unit_id', $area)->exists() || DB::table('waste_lots')->whereIn('material_unit_id', $area)->exists();
        if (! $used) {
            DB::table('units')->whereIn('id', $area)->delete();
            DB::statement("ALTER TABLE units MODIFY dimension ENUM('mass', 'volume', 'count', 'length') NOT NULL");
        }
    }
};
