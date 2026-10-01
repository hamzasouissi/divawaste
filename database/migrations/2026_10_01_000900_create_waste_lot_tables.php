<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const STATUSES = ['created', 'stored', 'awaiting_pickup', 'collected', 'treated', 'closed'];

    public function up(): void
    {
        Schema::create('waste_lots', function (Blueprint $table) {
            $table->id();
            $table->publicUlid();
            $table->unsignedBigInteger('company_id');
            $table->string('lot_number', 32);
            $table->unsignedBigInteger('site_id');
            $table->unsignedBigInteger('zone_id');
            $table->unsignedBigInteger('waste_type_id');
            $table->foreignId('packaging_type_id')->nullable()->constrained('packaging_types', 'id', 'fk_waste_lots_packaging')->restrictOnDelete();
            $table->enum('origin_type', ['created', 'split', 'grouping'])->default('created');
            $table->enum('status', self::STATUSES)->default('created');
            $table->enum('closed_reason', ['completed', 'split', 'grouped', 'cancelled'])->nullable();
            $table->boolean('is_hazardous');
            $table->foreignId('regulatory_waste_code_id')->nullable()->constrained('regulatory_waste_codes', 'id', 'fk_waste_lots_reg_code')->restrictOnDelete();
            $table->decimal('gross_weight_kg', 12, 3)->nullable();
            $table->decimal('tare_weight_kg', 12, 3)->nullable();
            $table->decimal('net_weight_kg', 12, 3);
            $table->decimal('quantity', 12, 3)->nullable();
            $table->foreignId('unit_id')->nullable()->constrained('units', 'id', 'fk_waste_lots_unit')->restrictOnDelete();
            $table->foreignId('color_family_id')->nullable()->constrained('color_families', 'id', 'fk_waste_lots_color')->restrictOnDelete();
            $table->string('color_label', 100)->nullable();
            $table->decimal('grammage_gsm', 7, 2)->nullable();
            $table->string('composition_label', 255)->nullable();
            $table->json('extra_attributes')->nullable();
            $table->string('notes', 1000)->nullable();
            $table->dateTime('generated_at');
            $table->dateTime('stored_at')->nullable();
            $table->dateTime('awaiting_pickup_at')->nullable();
            $table->dateTime('collected_at')->nullable();
            $table->dateTime('treated_at')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->foreignId('created_by_user_id')->constrained('users', 'id', 'fk_waste_lots_created_by')->restrictOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users', 'id', 'fk_waste_lots_updated_by')->restrictOnDelete();
            $table->unsignedBigInteger('created_device_id')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->datetimes();
            $table->dateTime('deleted_at')->nullable();
            $table->foreignId('deleted_by_user_id')->nullable()->constrained('users', 'id', 'fk_waste_lots_deleted_by')->restrictOnDelete();
            $table->string('deletion_reason', 500)->nullable();

            $table->foreign(['company_id', 'site_id'], 'fk_waste_lots_site')->references(['company_id', 'id'])->on('sites')->restrictOnDelete();
            $table->foreign(['company_id', 'site_id', 'zone_id'], 'fk_waste_lots_zone')->references(['company_id', 'site_id', 'id'])->on('zones')->restrictOnDelete();
            $table->foreign(['company_id', 'waste_type_id'], 'fk_waste_lots_waste_type')->references(['company_id', 'id'])->on('waste_types')->restrictOnDelete();
            $table->foreign(['company_id', 'created_device_id'], 'fk_waste_lots_device')->references(['company_id', 'id'])->on('devices')->restrictOnDelete();
            $table->unique(['company_id', 'lot_number'], 'uq_waste_lots_company_number');
            $table->unique(['company_id', 'id'], 'uq_waste_lots_company_id_id');
            $table->index(['company_id', 'status', 'site_id', 'zone_id', 'waste_type_id', 'net_weight_kg'], 'ix_waste_lots_stock');
            $table->index(['company_id', 'site_id', 'generated_at'], 'ix_waste_lots_site_generated');
            $table->index(['company_id', 'generated_at'], 'ix_waste_lots_company_generated');
            $table->index(['company_id', 'site_id', 'zone_id'], 'ix_waste_lots_zone');
            $table->index(['company_id', 'waste_type_id'], 'ix_waste_lots_waste_type');
            $table->index(['company_id', 'site_id', 'updated_at', 'id'], 'ix_waste_lots_sync_pull');
            $table->index(['company_id', 'created_device_id'], 'ix_waste_lots_device');
        });
        DB::statement('ALTER TABLE waste_lots ADD CONSTRAINT chk_waste_lots_weight CHECK (net_weight_kg >= 0)');
        DB::statement("ALTER TABLE waste_lots ADD CONSTRAINT chk_waste_lots_closed CHECK ((status = 'closed') = (closed_reason IS NOT NULL))");

        Schema::create('waste_lot_compositions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('waste_lot_id');
            $table->foreignId('material_id')->constrained('materials', 'id', 'fk_wlc_material')->restrictOnDelete();
            $table->decimal('percentage', 5, 2);
            $table->datetimes();

            $table->foreign(['company_id', 'waste_lot_id'], 'fk_wlc_lot')->references(['company_id', 'id'])->on('waste_lots')->restrictOnDelete();
            $table->unique(['company_id', 'waste_lot_id', 'material_id'], 'uq_wlc_lot_material');
        });
        DB::statement('ALTER TABLE waste_lot_compositions ADD CONSTRAINT chk_wlc_percentage CHECK (percentage > 0 AND percentage <= 100)');

        Schema::create('lot_tags', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('waste_lot_id')->nullable();
            $table->enum('tag_type', ['qr', 'rfid_epc']);
            $table->string('tag_value', 64)->charset('ascii')->collation('ascii_bin');
            $table->enum('status', ['unassigned', 'active', 'revoked'])->default('active');
            $table->boolean('is_active_flag')->nullable();
            $table->dateTime('assigned_at')->nullable();
            $table->foreignId('assigned_by_user_id')->nullable()->constrained('users', 'id', 'fk_lot_tags_assigned_by')->restrictOnDelete();
            $table->dateTime('revoked_at')->nullable();
            $table->foreignId('revoked_by_user_id')->nullable()->constrained('users', 'id', 'fk_lot_tags_revoked_by')->restrictOnDelete();
            $table->string('revocation_reason', 255)->nullable();
            $table->unsignedSmallInteger('print_count')->default(0);
            $table->dateTime('last_printed_at')->nullable();
            $table->datetimes();

            $table->foreign(['company_id', 'waste_lot_id'], 'fk_lot_tags_lot')->references(['company_id', 'id'])->on('waste_lots')->restrictOnDelete();
            $table->unique(['tag_type', 'tag_value'], 'uq_lot_tags_value');
            $table->unique(['waste_lot_id', 'tag_type', 'is_active_flag'], 'uq_lot_tags_active');
            $table->index(['company_id', 'waste_lot_id'], 'ix_lot_tags_lot');
        });

        Schema::create('waste_lot_operations', function (Blueprint $table) {
            $table->id();
            $table->publicUlid();
            $table->unsignedBigInteger('company_id');
            $table->enum('operation_type', ['split', 'grouping']);
            $table->unsignedBigInteger('site_id');
            $table->decimal('input_total_kg', 14, 3);
            $table->decimal('output_total_kg', 14, 3);
            $table->string('notes', 1000)->nullable();
            $table->foreignId('performed_by_user_id')->constrained('users', 'id', 'fk_wlo_performed_by')->restrictOnDelete();
            $table->dateTime('performed_at', 3);
            $table->unsignedBigInteger('device_id')->nullable();
            $table->unsignedBigInteger('sync_operation_id')->nullable();
            $table->dateTime('recorded_at', 3);

            $table->foreign(['company_id', 'site_id'], 'fk_wlo_site')->references(['company_id', 'id'])->on('sites')->restrictOnDelete();
            $table->unique(['company_id', 'id'], 'uq_wlo_company_id_id');
            $table->index(['company_id', 'site_id', 'performed_at'], 'ix_wlo_site_performed');
        });

        Schema::create('waste_lot_lineage', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('waste_lot_operation_id');
            $table->unsignedBigInteger('parent_lot_id');
            $table->unsignedBigInteger('child_lot_id');
            $table->enum('relation_type', ['split', 'grouping']);
            $table->decimal('quantity_kg', 12, 3);
            $table->dateTime('recorded_at', 3);

            $table->foreign(['company_id', 'waste_lot_operation_id'], 'fk_wll_operation')->references(['company_id', 'id'])->on('waste_lot_operations')->restrictOnDelete();
            $table->foreign(['company_id', 'parent_lot_id'], 'fk_wll_parent')->references(['company_id', 'id'])->on('waste_lots')->restrictOnDelete();
            $table->foreign(['company_id', 'child_lot_id'], 'fk_wll_child')->references(['company_id', 'id'])->on('waste_lots')->restrictOnDelete();
            $table->unique(['company_id', 'parent_lot_id', 'child_lot_id'], 'uq_wll_edge');
            $table->index(['company_id', 'child_lot_id'], 'ix_wll_child');
            $table->index(['company_id', 'waste_lot_operation_id'], 'ix_wll_operation');
        });
        DB::statement('ALTER TABLE waste_lot_lineage ADD CONSTRAINT chk_wll_not_self CHECK (parent_lot_id <> child_lot_id)');

        Schema::create('waste_lot_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('waste_lot_id');
            $table->string('event_type', 40);
            $table->enum('from_status', self::STATUSES)->nullable();
            $table->enum('to_status', self::STATUSES)->nullable();
            // Logical references (no FK on this high-volume append-only table, blueprint §15.9).
            $table->unsignedBigInteger('from_site_id')->nullable();
            $table->unsignedBigInteger('to_site_id')->nullable();
            $table->unsignedBigInteger('from_zone_id')->nullable();
            $table->unsignedBigInteger('to_zone_id')->nullable();
            $table->decimal('weight_kg', 12, 3)->nullable();
            // Simplified MVP: weighings merged into events.
            $table->decimal('gross_weight_kg', 12, 3)->nullable();
            $table->decimal('tare_weight_kg', 12, 3)->nullable();
            $table->enum('weight_source', ['manual', 'scale', 'computed'])->nullable();
            $table->string('device_reference', 100)->nullable();
            $table->unsignedBigInteger('pickup_id')->nullable();
            $table->unsignedBigInteger('waste_lot_operation_id')->nullable();
            $table->json('payload')->nullable();
            $table->enum('source', ['web', 'mobile', 'provider_portal', 'api', 'sync', 'system']);
            $table->dateTime('occurred_at', 3);
            $table->dateTime('recorded_at', 3);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('actor_company_id')->nullable();
            $table->unsignedBigInteger('device_id')->nullable();
            $table->unsignedBigInteger('sync_operation_id')->nullable();

            $table->foreign(['company_id', 'waste_lot_id'], 'fk_wle_lot')->references(['company_id', 'id'])->on('waste_lots')->restrictOnDelete();
            $table->index(['company_id', 'waste_lot_id', 'occurred_at', 'id'], 'ix_wle_lot_timeline');
            $table->index(['company_id', 'occurred_at'], 'ix_wle_company_occurred');
        });
    }

    public function down(): void
    {
        foreach (['waste_lot_events', 'waste_lot_lineage', 'waste_lot_operations', 'lot_tags', 'waste_lot_compositions', 'waste_lots'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
