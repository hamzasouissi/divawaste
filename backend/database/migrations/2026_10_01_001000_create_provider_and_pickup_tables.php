<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $user = fn (Blueprint $t, string $col, string $fk) => $t->foreignId($col)->nullable()->constrained('users', 'id', $fk)->restrictOnDelete();

        Schema::create('provider_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique('uq_provider_profiles_company')->constrained('companies', 'id', 'fk_provider_profiles_company')->restrictOnDelete();
            $table->boolean('is_collector')->default(false);
            $table->boolean('is_transporter')->default(false);
            $table->boolean('is_recycler')->default(false);
            $table->boolean('is_eliminator')->default(false);
            $table->text('description')->nullable();
            $table->string('service_area', 500)->nullable();
            $table->string('public_contact_name', 150)->nullable();
            $table->string('public_contact_email', 191)->nullable();
            $table->string('public_contact_phone', 30)->nullable();
            $table->boolean('is_published')->default(false);
            $table->dateTime('published_at')->nullable();
            $table->datetimes();
            $table->index('is_published', 'ix_provider_profiles_published');
        });
        DB::statement('ALTER TABLE provider_profiles ADD CONSTRAINT chk_provider_profiles_role CHECK (is_collector + is_transporter + is_recycler + is_eliminator >= 1)');

        Schema::create('provider_accepted_wastes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies', 'id', 'fk_paw_company')->restrictOnDelete();
            $table->foreignId('waste_family_id')->nullable()->constrained('waste_families', 'id', 'fk_paw_family')->restrictOnDelete();
            $table->foreignId('waste_catalog_item_id')->nullable()->constrained('waste_catalog_items', 'id', 'fk_paw_catalog_item')->restrictOnDelete();
            $table->foreignId('regulatory_waste_code_id')->nullable()->constrained('regulatory_waste_codes', 'id', 'fk_paw_reg_code')->restrictOnDelete();
            $table->foreignId('treatment_channel_id')->constrained('treatment_channels', 'id', 'fk_paw_channel')->restrictOnDelete();
            $table->decimal('min_quantity_kg', 12, 3)->nullable();
            $table->string('notes', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->datetimes();
            $table->index(['company_id', 'is_active'], 'ix_paw_company');
            $table->index(['waste_catalog_item_id', 'is_active'], 'ix_paw_catalog_item');
            $table->index(['waste_family_id', 'is_active'], 'ix_paw_family');
            $table->index(['regulatory_waste_code_id', 'is_active'], 'ix_paw_reg_code');
        });
        DB::statement('ALTER TABLE provider_accepted_wastes ADD CONSTRAINT chk_paw_target CHECK (waste_family_id IS NOT NULL OR waste_catalog_item_id IS NOT NULL OR regulatory_waste_code_id IS NOT NULL)');

        Schema::create('provider_accreditations', function (Blueprint $table) use ($user) {
            $table->id();
            $table->publicUlid();
            $table->foreignId('company_id')->constrained('companies', 'id', 'fk_pa_company')->restrictOnDelete();
            $table->foreignId('accreditation_type_id')->constrained('accreditation_types', 'id', 'fk_pa_type')->restrictOnDelete();
            $table->string('reference_number', 100);
            $table->string('issuing_authority', 191)->nullable();
            $table->date('issued_on')->nullable();
            $table->date('valid_from');
            $table->date('expires_on');
            $table->unsignedBigInteger('stored_file_id');
            $table->enum('review_status', ['pending', 'approved', 'rejected', 'revoked'])->default('pending');
            $user($table, 'reviewed_by_user_id', 'fk_pa_reviewed_by');
            $table->dateTime('reviewed_at')->nullable();
            $table->string('rejection_reason', 1000)->nullable();
            $table->dateTime('notified_30d_at')->nullable();
            $table->dateTime('notified_7d_at')->nullable();
            $table->dateTime('notified_expired_at')->nullable();
            $table->unsignedBigInteger('superseded_by_accreditation_id')->nullable();
            $user($table, 'created_by_user_id', 'fk_pa_created_by');
            $table->datetimes();
            $table->dateTime('deleted_at')->nullable();
            $user($table, 'deleted_by_user_id', 'fk_pa_deleted_by');
            $table->string('deletion_reason', 500)->nullable();

            $table->foreign(['company_id', 'stored_file_id'], 'fk_pa_file')->references(['company_id', 'id'])->on('stored_files')->restrictOnDelete();
            $table->unique(['company_id', 'id'], 'uq_pa_company_id_id');
            $table->foreign(['company_id', 'superseded_by_accreditation_id'], 'fk_pa_superseded_by')->references(['company_id', 'id'])->on('provider_accreditations')->restrictOnDelete();
            $table->unique(['company_id', 'accreditation_type_id', 'reference_number', 'valid_from'], 'uq_pa_reference');
            $table->index(['review_status', 'expires_on'], 'ix_pa_review_expires');
            $table->index(['company_id', 'expires_on'], 'ix_pa_company_expires');
        });
        DB::statement('ALTER TABLE provider_accreditations ADD CONSTRAINT chk_pa_dates CHECK (expires_on >= valid_from)');

        Schema::create('provider_partnerships', function (Blueprint $table) use ($user) {
            $table->id();
            $table->publicUlid();
            $table->foreignId('company_id')->constrained('companies', 'id', 'fk_pp_company')->restrictOnDelete();
            $table->foreignId('provider_company_id')->constrained('companies', 'id', 'fk_pp_provider')->restrictOnDelete();
            $table->enum('status', ['active', 'suspended', 'ended'])->default('active');
            $table->string('internal_reference', 100)->nullable();
            $table->string('notes', 1000)->nullable();
            $table->dateTime('started_at');
            $table->dateTime('ended_at')->nullable();
            $user($table, 'created_by_user_id', 'fk_pp_created_by');
            $table->datetimes();
            $table->unique(['company_id', 'provider_company_id'], 'uq_pp_pair');
            $table->index(['provider_company_id', 'status'], 'ix_pp_provider');
        });
        DB::statement('ALTER TABLE provider_partnerships ADD CONSTRAINT chk_pp_not_self CHECK (company_id <> provider_company_id)');

        $statuses = ['draft', 'requested', 'confirmed', 'refused', 'collected', 'received', 'completed', 'cancelled'];
        Schema::create('pickups', function (Blueprint $table) use ($user, $statuses) {
            $table->id();
            $table->publicUlid();
            $table->foreignId('company_id')->constrained('companies', 'id', 'fk_pickups_company')->restrictOnDelete();
            $table->string('pickup_number', 32);
            $table->unsignedBigInteger('site_id');
            $table->foreignId('provider_company_id')->constrained('companies', 'id', 'fk_pickups_provider')->restrictOnDelete();
            $table->foreignId('transporter_company_id')->nullable()->constrained('companies', 'id', 'fk_pickups_transporter')->restrictOnDelete();
            $table->enum('status', $statuses)->default('draft');
            $table->date('requested_date');
            $table->enum('requested_time_slot', ['morning', 'afternoon', 'any'])->default('any');
            $table->date('confirmed_date')->nullable();
            foreach (['submitted', 'confirmed', 'refused', 'collected', 'received', 'completed', 'cancelled'] as $step) {
                $table->dateTime("{$step}_at")->nullable();
            }
            foreach (['requested', 'confirmed', 'refused', 'collected', 'received', 'completed', 'cancelled'] as $actor) {
                $user($table, "{$actor}_by_user_id", "fk_pickups_{$actor}_by");
            }
            $table->string('refusal_reason', 1000)->nullable();
            $table->string('cancellation_reason', 1000)->nullable();
            $table->decimal('departure_weight_kg', 14, 3)->nullable();
            $table->enum('departure_weight_source', ['lots_sum', 'weighbridge'])->nullable();
            $table->decimal('received_weight_kg', 14, 3)->nullable();
            $table->decimal('weight_variance_kg', 14, 3)->nullable();
            $table->decimal('weight_variance_pct', 7, 2)->nullable();
            $table->decimal('variance_threshold_pct', 5, 2)->nullable();
            $table->boolean('variance_flagged')->default(false);
            $table->dateTime('variance_acknowledged_at')->nullable();
            $user($table, 'variance_acknowledged_by_user_id', 'fk_pickups_variance_ack_by');
            $table->string('variance_comment', 1000)->nullable();
            $table->string('vehicle_plate', 20)->nullable();
            $table->string('driver_name', 100)->nullable();
            $table->string('notes', 1000)->nullable();
            $table->char('currency_code', 3);
            $table->unsignedBigInteger('replaces_pickup_id')->nullable();
            $table->unsignedInteger('version')->default(1);
            $user($table, 'created_by_user_id', 'fk_pickups_created_by');
            $table->datetimes();

            $table->foreign(['company_id', 'site_id'], 'fk_pickups_site')->references(['company_id', 'id'])->on('sites')->restrictOnDelete();
            $table->foreign('currency_code', 'fk_pickups_currency')->references('code')->on('currencies')->restrictOnDelete();
            $table->unique(['company_id', 'pickup_number'], 'uq_pickups_company_number');
            $table->unique(['company_id', 'id'], 'uq_pickups_company_id_id');
            $table->foreign(['company_id', 'replaces_pickup_id'], 'fk_pickups_replaces')->references(['company_id', 'id'])->on('pickups')->restrictOnDelete();
            $table->index(['company_id', 'status', 'requested_date'], 'ix_pickups_company_status_date');
            $table->index(['provider_company_id', 'status', 'requested_date'], 'ix_pickups_provider_status_date');
            $table->index(['transporter_company_id', 'status'], 'ix_pickups_transporter_status');
            $table->index(['company_id', 'site_id', 'collected_at'], 'ix_pickups_site_collected');
        });
        DB::statement('ALTER TABLE pickups ADD CONSTRAINT chk_pickups_parties CHECK (provider_company_id <> company_id)');

        Schema::create('pickup_lots', function (Blueprint $table) use ($user) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('pickup_id');
            $table->unsignedBigInteger('waste_lot_id');
            $table->foreignId('provider_company_id')->constrained('companies', 'id', 'fk_pl_provider')->restrictOnDelete();
            $table->foreignId('transporter_company_id')->nullable()->constrained('companies', 'id', 'fk_pl_transporter')->restrictOnDelete();
            $table->string('lot_number_snapshot', 32);
            $table->string('qr_tag_value_snapshot', 64)->charset('ascii')->collation('ascii_bin')->nullable();
            $table->string('waste_type_label_snapshot', 150);
            $table->foreignId('regulatory_waste_code_id')->nullable()->constrained('regulatory_waste_codes', 'id', 'fk_pl_reg_code')->restrictOnDelete();
            $table->string('regulatory_code_snapshot', 20)->nullable();
            $table->boolean('is_hazardous');
            $table->enum('line_status', ['planned', 'loaded', 'not_loaded', 'received', 'rejected', 'treated'])->default('planned');
            $table->boolean('is_active_flag')->nullable()->default(true);
            $table->decimal('declared_weight_kg', 12, 3);
            $table->decimal('departure_weight_kg', 12, 3)->nullable();
            $table->decimal('received_weight_kg', 12, 3)->nullable();
            $table->decimal('weight_variance_kg', 12, 3)->nullable();
            $table->decimal('weight_variance_pct', 7, 2)->nullable();
            $table->boolean('variance_flagged')->default(false);
            $table->foreignId('planned_treatment_channel_id')->nullable()->constrained('treatment_channels', 'id', 'fk_pl_planned_channel')->restrictOnDelete();
            $table->foreignId('actual_treatment_channel_id')->nullable()->constrained('treatment_channels', 'id', 'fk_pl_actual_channel')->restrictOnDelete();
            $table->dateTime('treated_at')->nullable();
            $table->string('rejection_reason', 500)->nullable();
            // FK to hazardous_waste_manifests added with the compliance batch.
            $table->unsignedBigInteger('hazardous_waste_manifest_id')->nullable();
            $table->enum('pricing_mode', ['per_kg', 'per_tonne', 'flat'])->nullable();
            $table->decimal('unit_price', 15, 3)->nullable();
            $table->enum('price_basis', ['received', 'departure', 'flat'])->nullable();
            $table->decimal('net_amount', 15, 3)->nullable();
            $table->char('currency_code', 3)->nullable();
            $table->dateTime('loaded_at')->nullable();
            $user($table, 'loaded_by_user_id', 'fk_pl_loaded_by');
            $table->dateTime('received_at')->nullable();
            $user($table, 'received_by_user_id', 'fk_pl_received_by');
            $table->datetimes();

            $table->foreign(['company_id', 'pickup_id'], 'fk_pl_pickup')->references(['company_id', 'id'])->on('pickups')->restrictOnDelete();
            $table->foreign(['company_id', 'waste_lot_id'], 'fk_pl_lot')->references(['company_id', 'id'])->on('waste_lots')->restrictOnDelete();
            $table->foreign('currency_code', 'fk_pl_currency')->references('code')->on('currencies')->restrictOnDelete();
            $table->unique(['company_id', 'id'], 'uq_pl_company_id_id');
            $table->unique(['company_id', 'pickup_id', 'waste_lot_id'], 'uq_pl_pickup_lot');
            $table->unique(['waste_lot_id', 'is_active_flag'], 'uq_pl_active_lot');
            $table->index(['company_id', 'waste_lot_id'], 'ix_pl_lot');
            $table->index(['provider_company_id', 'pickup_id'], 'ix_pl_provider_pickup');
            $table->index(['pickup_id', 'qr_tag_value_snapshot'], 'ix_pl_pickup_tag');
            $table->index(['company_id', 'hazardous_waste_manifest_id'], 'ix_pl_manifest');
        });
    }

    public function down(): void
    {
        foreach (['pickup_lots', 'pickups', 'provider_partnerships', 'provider_accreditations', 'provider_accepted_wastes', 'provider_profiles'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
