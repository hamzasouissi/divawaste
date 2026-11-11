<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a raw material is, and how each waste it leaves is estimated.
 *
 *  - its NATURE: fabric, thread, trim (buttons, zips, labels), packaging, chemical, other; and, for one counted in
 *    pieces, the weight of one piece (a carton: 0.45 kg), to turn pieces into kilograms;
 *  - each WASTE TYPE it leaves has its own calculation:
 *      cutting   a share of the cutting waste of the fabric: kg of fabric × (100 − efficiency) % × share %
 *      loss_rate a part of what is consumed:                  kg consumed × rate %
 *      per_unit  a weight per unit consumed:                  quantity consumed × kg per unit (an empty cone)
 *      whole     all of it:                                   kg consumed (packaging thrown away)
 *
 * A fabric (metres with its laize and grammage) is said to be one; the lines already given keep the cutting share for
 * a fabric, and wait for their rate for anything else.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('raw_materials', function (Blueprint $table) {
            $table->string('kind', 20)->nullable()->after('category');
            $table->decimal('unit_weight_kg', 12, 6)->nullable()->after('width_cm');
        });
        $metre = DB::table('units')->where('code', 'm')->value('id');
        DB::table('raw_materials')->where('unit_id', $metre)->whereNotNull('width_cm')->whereNotNull('grammage_gsm')->update(['kind' => 'fabric']);

        Schema::table('raw_material_waste_types', function (Blueprint $table) {
            $table->string('method', 20)->default('cutting')->after('waste_type_id');
            $table->decimal('share_pct', 5, 2)->nullable()->change();
            $table->decimal('rate_pct', 6, 3)->nullable()->after('share_pct');
            $table->decimal('kg_per_unit', 12, 6)->nullable()->after('rate_pct');
        });
        $fabrics = DB::table('raw_materials')->where('kind', 'fabric')->pluck('id');
        DB::table('raw_material_waste_types')->whereNotIn('raw_material_id', $fabrics)->update(['method' => 'loss_rate', 'share_pct' => null]);
    }

    public function down(): void
    {
        DB::table('raw_material_waste_types')->where('method', '!=', 'cutting')->delete();
        Schema::table('raw_material_waste_types', function (Blueprint $table) {
            $table->dropColumn(['method', 'rate_pct', 'kg_per_unit']);
        });
        Schema::table('raw_materials', function (Blueprint $table) {
            $table->dropColumn(['kind', 'unit_weight_kg']);
        });
    }
};
