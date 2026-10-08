<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Where the waste comes from in production. All optional: a lot may say its manufacturing order, only the
        // stage it was produced at, the raw material it is made of — or nothing.
        Schema::table('waste_lots', function (Blueprint $table) {
            $table->unsignedBigInteger('production_order_id')->nullable()->after('waste_type_id');
            $table->foreignId('production_step_id')->nullable()->after('production_order_id')->constrained('production_steps', 'id', 'fk_waste_lots_production_step')->restrictOnDelete();
            $table->unsignedBigInteger('raw_material_id')->nullable()->after('production_step_id');
            $table->unsignedBigInteger('raw_material_batch_id')->nullable()->after('raw_material_id');

            $table->foreign(['company_id', 'production_order_id'], 'fk_waste_lots_production_order')->references(['company_id', 'id'])->on('production_orders')->restrictOnDelete();
            $table->foreign(['company_id', 'raw_material_id'], 'fk_waste_lots_raw_material')->references(['company_id', 'id'])->on('raw_materials')->restrictOnDelete();
            $table->foreign(['company_id', 'raw_material_batch_id'], 'fk_waste_lots_raw_material_batch')->references(['company_id', 'id'])->on('raw_material_batches')->restrictOnDelete();
            $table->index(['company_id', 'production_order_id'], 'ix_waste_lots_production_order');
            $table->index(['company_id', 'site_id', 'production_step_id', 'generated_at'], 'ix_waste_lots_step_generated');
            $table->index(['company_id', 'raw_material_id'], 'ix_waste_lots_raw_material');
        });
    }

    public function down(): void
    {
        Schema::table('waste_lots', function (Blueprint $table) {
            foreach (['fk_waste_lots_production_order', 'fk_waste_lots_raw_material', 'fk_waste_lots_raw_material_batch', 'fk_waste_lots_production_step'] as $key) {
                $table->dropForeign($key);
            }
            foreach (['ix_waste_lots_production_order', 'ix_waste_lots_step_generated', 'ix_waste_lots_raw_material'] as $index) {
                $table->dropIndex($index);
            }
            $table->dropColumn(['production_order_id', 'production_step_id', 'raw_material_id', 'raw_material_batch_id']);
        });
    }
};
