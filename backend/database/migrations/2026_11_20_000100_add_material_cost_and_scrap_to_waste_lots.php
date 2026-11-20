<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a lot of waste cost, and why it was scrapped.
 *
 *  - material_cost_per_kg: one kilogram of the raw material the waste comes from, priced by a batch (lot de matière) of
 *    it: (amount of the batch + its delivery cost) ÷ weight of the batch in kilograms; null when no batch, price or
 *    weight of the material is known;
 *  - material_value: what the waste lot's weight of that material cost (cost per kg × net weight);
 *  - cost_batch_id, cost_basis: the batch that priced it and why that one — 'lot' (the lot's own batch), 'consumption'
 *    (the batch its OF consumed), 'latest' (the last batch of the material received by the lot's date). It is the
 *    price's source, not a claim about the lot's origin (raw_material_batch_id says that).
 *  - scrap_code, scrap_reason: the code and the reason of the scrap, as the plant writes them (UE24, "trous dans le tissu").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('waste_lots', function (Blueprint $table) {
            $table->decimal('material_cost_per_kg', 15, 4)->nullable()->after('raw_material_batch_id');
            $table->decimal('material_value', 15, 3)->nullable()->after('material_cost_per_kg');
            $table->char('cost_currency_code', 3)->nullable()->after('material_value');
            $table->unsignedBigInteger('cost_batch_id')->nullable()->after('cost_currency_code');
            $table->string('cost_basis', 20)->nullable()->after('cost_batch_id');
            $table->string('scrap_code', 30)->nullable()->after('cost_basis');
            $table->string('scrap_reason', 500)->nullable()->after('scrap_code');
            // A price source, kept up to date by the application: a deleted batch simply leaves the lot unpriced.
            $table->foreign('cost_batch_id', 'fk_waste_lots_cost_batch')->references('id')->on('raw_material_batches')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('waste_lots', function (Blueprint $table) {
            $table->dropForeign('fk_waste_lots_cost_batch');
            $table->dropColumn(['material_cost_per_kg', 'material_value', 'cost_currency_code', 'cost_batch_id', 'cost_basis', 'scrap_code', 'scrap_reason']);
        });
    }
};
