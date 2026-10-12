<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a partner without an account asks of a collection, as the company that works with it wrote it down — from a
 * contract, an email, a phone call. These lines belong to that company: the partner never confirmed them on the
 * platform, which is what tells them apart from the requirements a provider publishes itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_requirements', function (Blueprint $table) {
            $table->id();
            $table->publicUlid();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('external_partner_id');
            $table->foreignId('waste_family_id')->constrained('waste_families', 'id', 'fk_partner_req_family')->restrictOnDelete();
            $table->foreignId('treatment_channel_id')->constrained('treatment_channels', 'id', 'fk_partner_req_channel')->restrictOnDelete();
            $table->decimal('min_quantity', 12, 3)->nullable();
            $table->string('min_unit_code', 10)->nullable();
            $table->foreignId('min_packaging_type_id')->nullable()->constrained('packaging_types', 'id', 'fk_partner_req_min_packaging')->restrictOnDelete();
            $table->foreignId('required_packaging_type_id')->nullable()->constrained('packaging_types', 'id', 'fk_partner_req_required_packaging')->restrictOnDelete();
            // false = a collection below the requirement is warned about; true = it is not requested.
            $table->boolean('is_blocking')->default(false);
            // Where the company read it: a contract number, the date of an email.
            $table->string('contract_reference', 191)->nullable();
            $table->string('notes', 500)->nullable();
            // Once the partner has an account: when someone last looked at this line and decided to keep it.
            $table->dateTime('reviewed_at')->nullable();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users', 'id', 'fk_partner_req_reviewed_by')->restrictOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users', 'id', 'fk_partner_req_created_by')->restrictOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users', 'id', 'fk_partner_req_updated_by')->restrictOnDelete();
            $table->datetimes();

            $table->foreign(['company_id', 'external_partner_id'], 'fk_partner_req_partner')->references(['company_id', 'id'])->on('external_partners')->restrictOnDelete();
            $table->index(['company_id', 'external_partner_id'], 'ix_partner_req_partner');
        });
        DB::statement('ALTER TABLE partner_requirements ADD CONSTRAINT chk_partner_req_asks CHECK (min_quantity > 0 OR required_packaging_type_id IS NOT NULL)');
        DB::statement('ALTER TABLE partner_requirements ADD CONSTRAINT chk_partner_req_unit CHECK ((min_quantity IS NULL) = (min_unit_code IS NULL))');
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_requirements');
    }
};
