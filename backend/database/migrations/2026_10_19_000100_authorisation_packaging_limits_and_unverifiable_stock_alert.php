<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two things a limit could not say until now.
 *
 * An authorisation that names several packagings limits each of them on its own ("300 bags" and "40 bales" are two
 * limits). Only a document that states one combined quantity ("40 bales or 100 bags") shares it, and says so with
 * `shared_packaging_allowance`. Authorisations recorded before this flag were entered under a form that read "or" and
 * were counted as one shared quantity: those with several packagings keep that reading, so nothing that was refused
 * becomes allowed by a migration. They can be reviewed one by one.
 *
 * A stock limit can be in a third state: below its maximum on what is known, with lots nobody weighed. It is stored
 * like the two others so that people are told once, when it happens.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('waste_authorizations', function (Blueprint $table) {
            $table->boolean('shared_packaging_allowance')->default(false)->after('max_lots');
        });
        DB::table('waste_authorizations')->whereIn('id', DB::table('waste_authorization_limits')->groupBy('waste_authorization_id')
            ->havingRaw('COUNT(*) > 1')->select('waste_authorization_id'))->update(['shared_packaging_allowance' => true]);

        DB::statement("ALTER TABLE stock_thresholds MODIFY alert_level ENUM('warning', 'exceeded', 'unverifiable') NULL");
    }

    public function down(): void
    {
        DB::table('stock_thresholds')->where('alert_level', 'unverifiable')->update(['alert_level' => null, 'alerted_at' => null]);
        DB::statement("ALTER TABLE stock_thresholds MODIFY alert_level ENUM('warning', 'exceeded') NULL");
        Schema::table('waste_authorizations', function (Blueprint $table) {
            $table->dropColumn('shared_packaging_allowance');
        });
    }
};
