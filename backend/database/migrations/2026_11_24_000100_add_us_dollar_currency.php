<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The US dollar: plants that make for export buy and cost their materials in dollars (a scrap report's unit costs, the
 * prices of imported material batches). Reference data, added where it is missing (ReferenceDataSeeder lists it too).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('currencies')->insertOrIgnore([
            'code' => 'USD', 'name' => json_encode(['fr' => 'Dollar américain', 'en' => 'US dollar'], JSON_UNESCAPED_UNICODE),
            'minor_unit' => 2, 'symbol' => '$', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Only when nothing is priced in it: a currency in use stays.
        $used = collect(['raw_material_batches', 'waste_lots'])->contains(fn (string $table) => DB::table($table)
            ->where($table === 'waste_lots' ? 'cost_currency_code' : 'currency_code', 'USD')->exists());
        if (! $used) {
            DB::table('currencies')->where('code', 'USD')->delete();
        }
    }
};
