<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How much of its raw material a waste lot is, and at what price — the lines of a scrap report (reference, quantity,
 * unit, unit cost, total cost).
 *
 *  - material_quantity, material_unit_id: the quantity of the raw material scrapped, in a unit of the material
 *    (m, kg, piece; by default the material's own). Optional: the lot's weight still prices it when it is not given.
 *  - material_unit_price: a price per unit of that quantity typed by a person, in the company's currency. Empty: the
 *    price comes from a batch of the material, as before (cost_basis lot / consumption / latest). Typed: cost_basis
 *    'entered', and no batch.
 *  - material_unit_cost: the price per unit the value was computed with — the typed one, or the batch's per unit of
 *    the material (delivery included). Kept up to date by the application, like material_cost_per_kg.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('waste_lots', function (Blueprint $table) {
            $table->decimal('material_quantity', 15, 4)->nullable()->after('raw_material_batch_id');
            $table->unsignedBigInteger('material_unit_id')->nullable()->after('material_quantity');
            $table->decimal('material_unit_price', 15, 5)->nullable()->after('material_unit_id');
            $table->decimal('material_unit_cost', 15, 5)->nullable()->after('material_cost_per_kg');
            $table->foreign('material_unit_id', 'fk_waste_lots_material_unit')->references('id')->on('units')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('waste_lots', function (Blueprint $table) {
            $table->dropForeign('fk_waste_lots_material_unit');
            $table->dropColumn(['material_quantity', 'material_unit_id', 'material_unit_price', 'material_unit_cost']);
        });
    }
};
