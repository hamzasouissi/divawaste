<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Two lists of the company, kept apart:
 *
 *  - the WASTE a raw material leaves can be of several types, each with its share (a fabric: 85 % cutting scraps,
 *    15 % selvedges); the single type raw_materials.waste_type_id said becomes one line at 100 %;
 *  - the SIZES the company uses (XS, S, M… 2A, 4A…), in order. Size grids and OFs take their sizes from it; the sizes
 *    the grids and OFs already name join it, in the order they appear.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('raw_material_waste_types', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('raw_material_id');
            $table->unsignedBigInteger('waste_type_id');
            $table->decimal('share_pct', 5, 2);
            $table->unsignedSmallInteger('position')->default(0);
            $table->datetimes();

            $table->foreign('company_id', 'fk_rm_waste_types_company')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'raw_material_id'], 'fk_rm_waste_types_material')->references(['company_id', 'id'])->on('raw_materials')->cascadeOnDelete();
            $table->foreign(['company_id', 'waste_type_id'], 'fk_rm_waste_types_type')->references(['company_id', 'id'])->on('waste_types')->restrictOnDelete();
            $table->unique(['raw_material_id', 'waste_type_id'], 'uq_rm_waste_types_material_type');
        });

        DB::table('raw_materials')->whereNotNull('waste_type_id')->orderBy('id')->get(['id', 'company_id', 'waste_type_id'])
            ->each(fn ($row) => DB::table('raw_material_waste_types')->insert(['company_id' => $row->company_id, 'raw_material_id' => $row->id,
                'waste_type_id' => $row->waste_type_id, 'share_pct' => '100.00', 'position' => 0, 'created_at' => now(), 'updated_at' => now()]));

        Schema::table('raw_materials', function (Blueprint $table) {
            $table->dropForeign('fk_raw_materials_waste_type');
            $table->dropColumn('waste_type_id');
        });

        Schema::create('sizes', function (Blueprint $table) {
            $table->id();
            $table->publicUlid();
            $table->foreignId('company_id')->constrained('companies', 'id', 'fk_sizes_company')->restrictOnDelete();
            $table->string('code', 20);
            $table->unsignedInteger('position')->default(0);
            $table->datetimes();

            $table->unique(['company_id', 'code'], 'uq_sizes_company_code');
        });

        // The sizes already named by the grids, then by the OFs, each once per company, in the order they come.
        $seen = [];
        $count = [];
        $add = function (int $companyId, string $code) use (&$seen, &$count) {
            $code = mb_substr(trim($code), 0, 20);
            $key = $companyId.'|'.mb_strtolower($code);
            if ($code === '' || isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $count[$companyId] = ($count[$companyId] ?? 0) + 1;
            DB::table('sizes')->insert(['ulid' => strtoupper((string) Str::ulid()), 'company_id' => $companyId, 'code' => $code,
                'position' => $count[$companyId] * 10, 'created_at' => now(), 'updated_at' => now()]);
        };
        DB::table('size_grids')->orderBy('id')->get(['company_id', 'sizes'])
            ->each(fn ($grid) => array_map(fn ($size) => $add((int) $grid->company_id, (string) $size), json_decode((string) $grid->sizes, true) ?? []));
        DB::table('production_orders')->whereNotNull('size_quantities')->orderBy('id')->get(['company_id', 'size_quantities'])
            ->each(fn ($of) => array_map(fn ($line) => $add((int) $of->company_id, (string) ($line['size'] ?? '')), json_decode((string) $of->size_quantities, true) ?? []));
    }

    public function down(): void
    {
        Schema::dropIfExists('sizes');
        Schema::table('raw_materials', function (Blueprint $table) {
            $table->unsignedBigInteger('waste_type_id')->nullable();
            $table->foreign(['company_id', 'waste_type_id'], 'fk_raw_materials_waste_type')->references(['company_id', 'id'])->on('waste_types')->restrictOnDelete();
        });
        // The main type (the largest share) goes back on the raw material.
        DB::table('raw_material_waste_types')->orderBy('share_pct')->get(['raw_material_id', 'waste_type_id'])
            ->each(fn ($row) => DB::table('raw_materials')->where('id', $row->raw_material_id)->update(['waste_type_id' => $row->waste_type_id]));
        Schema::dropIfExists('raw_material_waste_types');
    }
};
