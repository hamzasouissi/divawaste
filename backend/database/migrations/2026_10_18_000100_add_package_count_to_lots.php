<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A lot is not a package: one lot may be twelve bags. A lot that has a packaging type counts its packages — one by
 * default, marked as a default until someone confirms or corrects it. A lot without packaging (loose) has no count.
 *
 * Existing lots that have a packaging type get the default of one, unconfirmed: it is what zones and authorisations
 * counted for them until now, so no figure moves, and nothing says anybody counted. Documents already issued keep
 * the number of packages they were issued with.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('waste_lots', function (Blueprint $table) {
            $table->unsignedInteger('package_count')->nullable()->after('packaging_type_id');
            // false = the default of one, or a figure nobody checked; true = entered or corrected by someone.
            $table->boolean('package_count_confirmed')->default(false)->after('package_count');
        });
        DB::table('waste_lots')->whereNotNull('packaging_type_id')->update(['package_count' => 1]);

        Schema::table('pickup_lots', function (Blueprint $table) {
            // The packaging and the number of packages as they stood when the lot was requested and loaded.
            $table->string('packaging_code', 40)->nullable()->after('unit_code');
            $table->unsignedInteger('package_count')->nullable()->after('packaging_code');
            // A count corrected after the lot left: kept beside the figure the decisions were taken with, never over it.
            $table->unsignedInteger('corrected_package_count')->nullable()->after('package_count');
            // What the authorisation was asked to cover for this line, and what it answered, when the pickup was requested.
            $table->json('authorization_decision')->nullable()->after('waste_authorization_id');
        });
        DB::statement('UPDATE pickup_lots pl JOIN waste_lots l ON l.id = pl.waste_lot_id JOIN packaging_types p ON p.id = l.packaging_type_id'
            .' SET pl.packaging_code = p.code, pl.package_count = 1');
    }

    public function down(): void
    {
        Schema::table('pickup_lots', fn (Blueprint $table) => $table->dropColumn(['packaging_code', 'package_count', 'corrected_package_count', 'authorization_decision']));
        Schema::table('waste_lots', fn (Blueprint $table) => $table->dropColumn(['package_count', 'package_count_confirmed']));
    }
};
