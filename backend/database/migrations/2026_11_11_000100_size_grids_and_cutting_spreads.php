<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sizes and cutting.
 *
 *  - a SIZE GRID is a company's list of sizes, in order (Adulte: XS S M L XL XXL), reused by its OFs;
 *  - an OF may follow a grid and say how many pieces of each size it makes;
 *  - a CUTTING SPREAD (matelas) is one spread of a fabric cut for an OF: the placement (marker length, efficiency,
 *    pieces of each size per layer) and its layers, planned then done. The fabric it uses and the cutting waste it is
 *    expected to leave come from it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('size_grids', function (Blueprint $table) {
            $table->id();
            $table->publicUlid();
            $table->foreignId('company_id')->constrained('companies', 'id', 'fk_size_grids_company')->restrictOnDelete();
            $table->string('name', 80);
            $table->json('sizes');                      // ["XS", "S", "M", …] in order
            $table->boolean('is_active')->default(true);
            $table->datetimes();

            $table->unique(['company_id', 'name'], 'uq_size_grids_company_name');
            $table->unique(['company_id', 'id'], 'uq_size_grids_company_id_id');
        });

        Schema::table('production_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('size_grid_id')->nullable()->after('quantity_produced');
            $table->json('size_quantities')->nullable()->after('size_grid_id');     // [{"size": "S", "quantity": 100}, …]
            $table->foreign(['company_id', 'size_grid_id'], 'fk_production_orders_size_grid')->references(['company_id', 'id'])->on('size_grids')->restrictOnDelete();
        });

        Schema::create('cutting_spreads', function (Blueprint $table) {
            $table->id();
            $table->publicUlid();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('production_order_id');
            $table->unsignedBigInteger('raw_material_id');
            $table->unsignedBigInteger('raw_material_batch_id')->nullable();
            $table->decimal('marker_length_m', 8, 3);
            $table->decimal('efficiency_pct', 5, 2);
            $table->decimal('end_allowance_cm', 6, 2)->nullable();      // added to each layer, at both ends together
            $table->json('ratio');                                     // [{"size": "S", "pieces": 1}, …] per layer
            $table->unsignedInteger('layers_planned');
            $table->unsignedInteger('layers_done')->nullable();
            $table->date('cut_on')->nullable();
            $table->string('notes', 500)->nullable();
            $table->dateTime('consumption_recorded_at')->nullable();
            $table->datetimes();

            $table->foreign('company_id', 'fk_cutting_spreads_company')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'production_order_id'], 'fk_cutting_spreads_of')->references(['company_id', 'id'])->on('production_orders')->cascadeOnDelete();
            $table->foreign(['company_id', 'raw_material_id'], 'fk_cutting_spreads_material')->references(['company_id', 'id'])->on('raw_materials')->restrictOnDelete();
            $table->foreign(['company_id', 'raw_material_batch_id'], 'fk_cutting_spreads_batch')->references(['company_id', 'id'])->on('raw_material_batches')->restrictOnDelete();
            $table->index(['company_id', 'production_order_id'], 'ix_cutting_spreads_of');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cutting_spreads');
        Schema::table('production_orders', function (Blueprint $table) {
            $table->dropForeign('fk_production_orders_size_grid');
            $table->dropColumn(['size_grid_id', 'size_quantities']);
        });
        Schema::dropIfExists('size_grids');
    }
};
