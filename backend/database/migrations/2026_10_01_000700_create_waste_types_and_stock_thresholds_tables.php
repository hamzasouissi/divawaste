<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('waste_types', function (Blueprint $table) {
            $table->id();
            $table->publicUlid();
            $table->foreignId('company_id')->constrained('companies', 'id', 'fk_waste_types_company')->restrictOnDelete();
            // NULL = custom type; several company variants may share one catalog item.
            $table->foreignId('waste_catalog_item_id')->nullable()->constrained('waste_catalog_items', 'id', 'fk_waste_types_catalog_item')->restrictOnDelete();
            $table->string('code', 40);
            $table->string('name', 150);
            $table->string('description', 1000)->nullable();
            $table->foreignId('waste_family_id')->constrained('waste_families', 'id', 'fk_waste_types_family')->restrictOnDelete();
            $table->foreignId('regulatory_waste_code_id')->nullable()->constrained('regulatory_waste_codes', 'id', 'fk_waste_types_reg_code')->restrictOnDelete();
            $table->boolean('is_hazardous')->default(false);
            $table->foreignId('default_unit_id')->constrained('units', 'id', 'fk_waste_types_unit')->restrictOnDelete();
            $table->foreignId('default_packaging_type_id')->nullable()->constrained('packaging_types', 'id', 'fk_waste_types_packaging')->restrictOnDelete();
            $table->foreignId('default_color_family_id')->nullable()->constrained('color_families', 'id', 'fk_waste_types_color')->restrictOnDelete();
            $table->decimal('default_grammage_gsm', 7, 2)->nullable();
            $table->foreignId('default_treatment_channel_id')->nullable()->constrained('treatment_channels', 'id', 'fk_waste_types_channel')->restrictOnDelete();
            // Simplified MVP: template data merged from waste_type_compositions / waste_type_unit_conversions.
            $table->json('default_composition')->nullable();
            $table->decimal('density_kg_m3', 10, 3)->nullable();
            $table->decimal('unit_weight_kg', 12, 3)->nullable();
            $table->json('extra_attributes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->dateTime('activated_at');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users', 'id', 'fk_waste_types_created_by')->restrictOnDelete();
            $table->datetimes();
            $table->dateTime('deleted_at')->nullable();

            $table->unique(['company_id', 'code'], 'uq_waste_types_company_code');
            $table->unique(['company_id', 'id'], 'uq_waste_types_company_id_id');
            $table->index(['company_id', 'is_active'], 'ix_waste_types_company_active');
        });

        Schema::create('stock_thresholds', function (Blueprint $table) {
            $table->id();
            $table->publicUlid();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('site_id');
            $table->unsignedBigInteger('zone_id')->nullable();
            $table->unsignedBigInteger('waste_type_id')->nullable();
            $table->unsignedBigInteger('zone_scope_key')->storedAs('IFNULL(zone_id, 0)');
            $table->unsignedBigInteger('waste_type_scope_key')->storedAs('IFNULL(waste_type_id, 0)');
            $table->decimal('max_quantity_kg', 14, 3);
            $table->unsignedTinyInteger('warning_pct')->default(90);
            // Simplified MVP: alert state merged from stock_alerts.
            $table->enum('alert_level', ['warning', 'exceeded'])->nullable();
            $table->dateTime('alerted_at')->nullable();
            $table->dateTime('last_evaluated_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users', 'id', 'fk_stock_thresholds_created_by')->restrictOnDelete();
            $table->datetimes();

            $table->foreign(['company_id', 'site_id'], 'fk_stock_thresholds_site')->references(['company_id', 'id'])->on('sites')->restrictOnDelete();
            $table->foreign(['company_id', 'site_id', 'zone_id'], 'fk_stock_thresholds_zone')->references(['company_id', 'site_id', 'id'])->on('zones')->restrictOnDelete();
            $table->foreign(['company_id', 'waste_type_id'], 'fk_stock_thresholds_waste_type')->references(['company_id', 'id'])->on('waste_types')->restrictOnDelete();
            $table->unique(['company_id', 'id'], 'uq_stock_thresholds_company_id_id');
            $table->unique(['company_id', 'site_id', 'zone_scope_key', 'waste_type_scope_key'], 'uq_stock_thresholds_subject');
            $table->index(['company_id', 'waste_type_id'], 'ix_stock_thresholds_waste_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_thresholds');
        Schema::dropIfExists('waste_types');
    }
};
