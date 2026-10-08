<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A partner recorded without an account may open one later: it is then tied to that company of the platform.
        Schema::table('external_partners', function (Blueprint $table) {
            $table->foreignId('linked_company_id')->nullable()->after('is_active')->constrained('companies', 'id', 'fk_external_partners_linked_company')->restrictOnDelete();
            $table->dateTime('linked_at')->nullable()->after('linked_company_id');
        });

        // Some waste may only leave the site under an authorisation (declaration signed by customs): a quota over a period.
        Schema::table('waste_types', function (Blueprint $table) {
            $table->boolean('requires_authorization')->default(false)->after('is_hazardous');
        });

        Schema::create('waste_authorizations', function (Blueprint $table) {
            $table->id();
            $table->publicUlid();
            $table->foreignId('company_id')->constrained('companies', 'id', 'fk_waste_authorizations_company')->restrictOnDelete();
            $table->unsignedBigInteger('waste_type_id');
            $table->string('reference', 100);
            $table->date('issued_on')->nullable();
            $table->date('valid_from');
            $table->date('valid_until');
            // The allowed quantity, written the way the document states it: a weight, a number of lots, and/or how
            // many lots of each packaging. At least one of them (checked by the application for the packaging lines).
            $table->decimal('max_kg', 14, 3)->nullable();
            $table->unsignedInteger('max_lots')->nullable();
            $table->unsignedTinyInteger('alert_pct')->default(80);
            $table->unsignedBigInteger('stored_file_id')->nullable();
            $table->string('notes', 1000)->nullable();
            // Withdrawn: no longer covers new pickups. Never deleted — past pickups keep pointing to it.
            $table->boolean('is_active')->default(true);
            $table->dateTime('notified_usage_at')->nullable();
            $table->dateTime('notified_30d_at')->nullable();
            $table->dateTime('notified_7d_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users', 'id', 'fk_waste_authorizations_created_by')->restrictOnDelete();
            $table->datetimes();

            $table->foreign(['company_id', 'waste_type_id'], 'fk_waste_authorizations_waste_type')->references(['company_id', 'id'])->on('waste_types')->restrictOnDelete();
            $table->foreign('stored_file_id', 'fk_waste_authorizations_file')->references('id')->on('stored_files')->restrictOnDelete();
            $table->unique(['company_id', 'id'], 'uq_waste_authorizations_company_id_id');
            $table->unique(['company_id', 'reference', 'waste_type_id'], 'uq_waste_authorizations_reference');
            $table->index(['company_id', 'waste_type_id', 'valid_from', 'valid_until'], 'ix_waste_authorizations_validity');
        });
        DB::statement('ALTER TABLE waste_authorizations ADD CONSTRAINT chk_waste_authorizations_dates CHECK (valid_until >= valid_from)');

        Schema::create('waste_authorization_limits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('waste_authorization_id');
            $table->foreignId('packaging_type_id')->constrained('packaging_types', 'id', 'fk_wal_packaging')->restrictOnDelete();
            $table->unsignedInteger('max_count');
            $table->datetimes();

            $table->foreign(['company_id', 'waste_authorization_id'], 'fk_wal_authorization')->references(['company_id', 'id'])->on('waste_authorizations')->cascadeOnDelete();
            $table->unique(['waste_authorization_id', 'packaging_type_id'], 'uq_wal_authorization_packaging');
        });

        // The authorisation a pickup line draws on, set when the pickup is requested: that is the reservation.
        Schema::table('pickup_lots', function (Blueprint $table) {
            $table->unsignedBigInteger('waste_authorization_id')->nullable()->after('hazardous_waste_manifest_id');
            $table->foreign(['company_id', 'waste_authorization_id'], 'fk_pl_authorization')->references(['company_id', 'id'])->on('waste_authorizations')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pickup_lots', function (Blueprint $table) {
            $table->dropForeign('fk_pl_authorization');
            $table->dropColumn('waste_authorization_id');
        });
        Schema::dropIfExists('waste_authorization_limits');
        Schema::dropIfExists('waste_authorizations');
        Schema::table('waste_types', fn (Blueprint $table) => $table->dropColumn('requires_authorization'));
        Schema::table('external_partners', function (Blueprint $table) {
            $table->dropForeign('fk_external_partners_linked_company');
            $table->dropColumn(['linked_company_id', 'linked_at']);
        });
    }
};
