<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A requirement may name one waste type, not only a family: a provider names an item of the shared catalogue, the
 * company one of its own waste types. Such a requirement replaces the one of the family for the lots of that type,
 * unless it is said to come in addition to it.
 *
 * A pickup keeps, beside the answer given when it was requested, the answer given on what was actually loaded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider_accepted_wastes', function (Blueprint $table) {
            // true = the lots of this item are also counted in the requirement of their family.
            $table->boolean('adds_to_family')->default(false)->after('is_blocking');
        });
        Schema::table('partner_requirements', function (Blueprint $table) {
            $table->unsignedBigInteger('waste_type_id')->nullable()->after('waste_family_id');
            $table->boolean('adds_to_family')->default(false)->after('is_blocking');
            $table->foreign(['company_id', 'waste_type_id'], 'fk_partner_req_waste_type')->references(['company_id', 'id'])->on('waste_types')->restrictOnDelete();
        });
        Schema::table('pickups', function (Blueprint $table) {
            $table->json('loading_requirements')->nullable()->after('provider_requirements');
        });
    }

    public function down(): void
    {
        Schema::table('pickups', fn (Blueprint $table) => $table->dropColumn('loading_requirements'));
        Schema::table('partner_requirements', function (Blueprint $table) {
            $table->dropForeign('fk_partner_req_waste_type');
            $table->dropIndex('fk_partner_req_waste_type');
            $table->dropColumn(['waste_type_id', 'adds_to_family']);
        });
        Schema::table('provider_accepted_wastes', fn (Blueprint $table) => $table->dropColumn('adds_to_family'));
    }
};
