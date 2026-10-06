<?php

namespace App\Modules\Common\Support;

use Illuminate\Support\Facades\DB;

/**
 * Per-company business numbers (lots, pickups…) from number_sequences, taken under a row lock
 * inside the caller's transaction: a rollback releases the number.
 */
final class NumberSequence
{
    public static function next(int $companyId, string $key, string $prefix, string $period, int $padding = 6): string
    {
        DB::table('number_sequences')->insertOrIgnore([
            'company_id' => $companyId, 'sequence_key' => $key, 'period_key' => $period, 'prefix' => $prefix,
            'next_value' => 1, 'padding' => $padding, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $row = DB::table('number_sequences')->where('company_id', $companyId)->where('sequence_key', $key)
            ->where('period_key', $period)->lockForUpdate()->first(['id', 'prefix', 'next_value', 'padding']);

        DB::table('number_sequences')->where('id', $row->id)->update(['next_value' => $row->next_value + 1, 'updated_at' => now()]);

        return $row->prefix.str_pad((string) $row->next_value, (int) $row->padding, '0', STR_PAD_LEFT);
    }
}
