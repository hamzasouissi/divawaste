<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Spinning, weaving and knitting are workshops (zone types) but were no production step: an OF of a knitting mill
 * could not name its step, and a lot made in a knitting zone could not be tied to one (the owner's test of the guides,
 * 2026-11-02). They become default steps, before cutting, with the codes of their zone types.
 */
return new class extends Migration
{
    private const STEPS = [['spinning', 'Filature', 'Spinning', 2], ['weaving', 'Tissage', 'Weaving', 4], ['knitting', 'Tricotage', 'Knitting', 6]];

    public function up(): void
    {
        $now = now();
        foreach (self::STEPS as [$code, $fr, $en, $order]) {
            if (! DB::table('production_steps')->where('code', $code)->exists()) {
                DB::table('production_steps')->insert(['code' => $code, 'name' => json_encode(['fr' => $fr, 'en' => $en], JSON_UNESCAPED_UNICODE),
                    'sort_order' => $order, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        foreach (self::STEPS as [$code]) {
            $id = DB::table('production_steps')->whereNull('company_id')->where('code', $code)->value('id');
            if ($id !== null && ! DB::table('production_order_steps')->where('production_step_id', $id)->exists()) {
                DB::table('production_steps')->where('id', $id)->delete();
            }
        }
    }
};
