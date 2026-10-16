<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The weight a site may hold was a second way of saying what a stock limit on the whole site says (every zone, every
 * waste type), in kilograms only. It becomes that limit: same figure, same alert percentage, the state already announced
 * kept so nobody is told twice. A site that already has a weight limit on its whole stock keeps that one.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sites = DB::table('sites')->whereNotNull('capacity_kg')->where('capacity_kg', '>', 0)->get(['id', 'company_id', 'capacity_kg', 'capacity_alert_pct']);
        foreach ($sites as $site) {
            $exists = DB::table('stock_thresholds')->where('site_id', $site->id)->whereNull('zone_id')->whereNull('waste_type_id')->where('measure_key', 'mass')->exists();
            if ($exists) {
                continue;
            }
            $announced = DB::table('capacity_alerts')->where('site_id', $site->id)->whereNull('zone_id')->where('capacity_type', 'site_load')->first(['alert_level', 'alerted_at', 'last_evaluated_at']);
            DB::table('stock_thresholds')->insert([
                'ulid' => strtoupper((string) Str::ulid()), 'company_id' => $site->company_id, 'site_id' => $site->id, 'zone_id' => null, 'waste_type_id' => null,
                'max_quantity' => $site->capacity_kg, 'unit_code' => 'kg', 'packaging_type_id' => null, 'measure_key' => 'mass',
                'warning_pct' => $site->capacity_alert_pct ?? 90, 'alert_level' => $announced?->alert_level, 'alerted_at' => $announced?->alerted_at,
                'last_evaluated_at' => $announced?->last_evaluated_at, 'is_active' => true, 'created_by_user_id' => null, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        DB::table('capacity_alerts')->where('capacity_type', 'site_load')->delete();
        Schema::table('sites', fn (Blueprint $table) => $table->dropColumn(['capacity_kg', 'capacity_alert_pct']));
    }

    /**
     * The columns come back empty: the limits made from them stay, and keep watching the sites. The exact way back is
     * the backup taken before the upgrade.
     */
    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->decimal('capacity_kg', 14, 3)->nullable()->after('timezone');
            $table->unsignedTinyInteger('capacity_alert_pct')->default(90)->after('capacity_kg');
        });
    }
};
