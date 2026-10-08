<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A transporter or a receiver the company works with and that has no account on the platform.
        Schema::create('external_partners', function (Blueprint $table) {
            $table->id();
            $table->publicUlid();
            $table->foreignId('company_id')->constrained('companies', 'id', 'fk_external_partners_company')->restrictOnDelete();
            $table->string('legal_name', 191);
            $table->string('tax_id', 40)->nullable();
            $table->string('address_line1', 191)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email', 191)->nullable();
            $table->string('contact_name', 150)->nullable();
            $table->boolean('is_transporter')->default(false);
            $table->boolean('is_recycler')->default(false);
            $table->boolean('is_eliminator')->default(false);
            // The authorisation the partner showed the company: nobody reviews it on the platform.
            $table->string('accreditation_reference', 100)->nullable();
            $table->date('accreditation_expires_on')->nullable();
            $table->boolean('covers_hazardous')->default(false);
            $table->string('notes', 1000)->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users', 'id', 'fk_external_partners_created_by')->restrictOnDelete();
            $table->datetimes();

            $table->unique(['company_id', 'id'], 'uq_external_partners_company_id_id');
            $table->index(['company_id', 'is_active'], 'ix_external_partners_company_active');
        });
        DB::statement('ALTER TABLE external_partners ADD CONSTRAINT chk_external_partners_role CHECK (is_transporter + is_recycler + is_eliminator >= 1)');

        // The receiver of a pickup is a company of the platform or an external partner; the transporter may be a third party.
        Schema::table('pickups', function (Blueprint $table) {
            $table->unsignedBigInteger('provider_company_id')->nullable()->change();
            $table->unsignedBigInteger('external_provider_id')->nullable()->after('provider_company_id');
            $table->unsignedBigInteger('external_transporter_id')->nullable()->after('transporter_company_id');
            $table->foreign(['company_id', 'external_provider_id'], 'fk_pickups_external_provider')->references(['company_id', 'id'])->on('external_partners')->restrictOnDelete();
            $table->foreign(['company_id', 'external_transporter_id'], 'fk_pickups_external_transporter')->references(['company_id', 'id'])->on('external_partners')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE pickups ADD CONSTRAINT chk_pickups_receiver CHECK ((provider_company_id IS NOT NULL) + (external_provider_id IS NOT NULL) = 1)');
        DB::statement('ALTER TABLE pickups ADD CONSTRAINT chk_pickups_transporter CHECK (transporter_company_id IS NULL OR external_transporter_id IS NULL)');

        Schema::table('pickup_lots', function (Blueprint $table) {
            $table->unsignedBigInteger('provider_company_id')->nullable()->change();
        });

        // Every waste leaves with a tracking slip, hazardous or not; a non-hazardous one may have no regulatory code.
        Schema::table('hazardous_waste_manifests', function (Blueprint $table) {
            $table->unsignedBigInteger('regulatory_waste_code_id')->nullable()->change();
            $table->boolean('is_hazardous')->default(true)->after('regulatory_waste_code_id');
        });

        // A party may sign by hand on the printed slip: the name of the person who signed is then typed in.
        Schema::table('document_signatures', function (Blueprint $table) {
            $table->string('signer_name', 150)->nullable()->after('signer_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('document_signatures', fn (Blueprint $table) => $table->dropColumn('signer_name'));
        Schema::table('hazardous_waste_manifests', fn (Blueprint $table) => $table->dropColumn('is_hazardous'));
        DB::statement('ALTER TABLE pickups DROP CONSTRAINT chk_pickups_receiver');
        DB::statement('ALTER TABLE pickups DROP CONSTRAINT chk_pickups_transporter');
        Schema::table('pickups', function (Blueprint $table) {
            $table->dropForeign('fk_pickups_external_provider');
            $table->dropForeign('fk_pickups_external_transporter');
            $table->dropColumn(['external_provider_id', 'external_transporter_id']);
        });
        Schema::dropIfExists('external_partners');
    }
};
