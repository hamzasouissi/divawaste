<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('waste_families', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique('uq_waste_families_code');
            $table->json('name');
            $table->boolean('is_textile')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->datetimes();
        });

        Schema::create('materials', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique('uq_materials_code');
            $table->json('name');
            $table->enum('material_class', ['natural', 'synthetic', 'artificial', 'other']);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->datetimes();
        });

        Schema::create('color_families', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique('uq_color_families_code');
            $table->json('name');
            $table->char('hex_color', 7)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->datetimes();
        });

        Schema::create('treatment_channels', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique('uq_treatment_channels_code');
            $table->json('name');
            $table->boolean('is_valorization');
            $table->boolean('is_landfill')->default(false);
            $table->unsignedTinyInteger('hierarchy_rank');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->datetimes();
        });

        Schema::create('regulatory_waste_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code_system', 20);
            $table->string('code', 20);
            $table->foreignId('parent_id')->nullable()->constrained('regulatory_waste_codes', 'id', 'fk_regulatory_waste_codes_parent')->restrictOnDelete();
            $table->unsignedTinyInteger('level');
            $table->json('label');
            $table->boolean('is_hazardous')->default(false);
            $table->boolean('is_selectable')->default(true);
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->datetimes();

            $table->unique(['code_system', 'code'], 'uq_regulatory_waste_codes_system_code');
            $table->index(['code_system', 'is_selectable', 'is_hazardous'], 'ix_regulatory_waste_codes_system_selectable');
        });

        Schema::create('waste_catalog_items', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique('uq_waste_catalog_items_code');
            $table->json('name');
            $table->json('description')->nullable();
            $table->foreignId('waste_family_id')->constrained('waste_families', 'id', 'fk_waste_catalog_items_family')->restrictOnDelete();
            // Simplified MVP: one default code (TN). Per-country mapping table is V2.
            $table->foreignId('default_regulatory_waste_code_id')->nullable()->constrained('regulatory_waste_codes', 'id', 'fk_waste_catalog_items_reg_code')->restrictOnDelete();
            $table->boolean('default_is_hazardous')->default(false);
            $table->foreignId('default_unit_id')->constrained('units', 'id', 'fk_waste_catalog_items_unit')->restrictOnDelete();
            $table->foreignId('default_packaging_type_id')->nullable()->constrained('packaging_types', 'id', 'fk_waste_catalog_items_packaging')->restrictOnDelete();
            $table->foreignId('default_treatment_channel_id')->nullable()->constrained('treatment_channels', 'id', 'fk_waste_catalog_items_channel')->restrictOnDelete();
            $table->decimal('default_density_kg_m3', 10, 3)->nullable();
            $table->decimal('default_unit_weight_kg', 12, 3)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->datetimes();

            $table->index(['waste_family_id', 'is_active'], 'ix_waste_catalog_items_family');
        });
    }

    public function down(): void
    {
        foreach (['waste_catalog_items', 'regulatory_waste_codes', 'treatment_channels', 'color_families', 'materials', 'waste_families'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
