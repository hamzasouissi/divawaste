<?php

namespace App\Modules\Production\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Pieces produced per site and month, counted from the manufacturing orders: a finished order counts its produced
 * quantity in the month it finished, on the site of its last stage. The dashboard's "waste per piece" reads these
 * figures. A month somebody typed in by hand is never overwritten: the person who typed it knows better.
 */
final class ProductionVolumes
{
    public static function sync(int $companyId): void
    {
        $rows = DB::table('production_orders as o')
            ->join('production_order_steps as s', fn ($join) => $join->on('s.production_order_id', '=', 'o.id')
                ->whereRaw('s.sequence = (select max(l.sequence) from production_order_steps l where l.production_order_id = o.id)'))
            ->where('o.company_id', $companyId)->where('o.status', 'completed')->whereNotNull('o.finished_on')->whereNotNull('o.quantity_produced')
            ->groupBy('s.site_id', DB::raw("DATE_FORMAT(o.finished_on, '%Y-%m-01')"))
            ->get(['s.site_id', DB::raw("DATE_FORMAT(o.finished_on, '%Y-%m-01') as month"), DB::raw('SUM(o.quantity_produced) as pieces')]);

        DB::transaction(function () use ($companyId, $rows) {
            $kept = [];
            foreach ($rows as $row) {
                $key = ['company_id' => $companyId, 'site_id' => $row->site_id, 'period_month' => $row->month];
                $existing = DB::table('production_volumes')->where($key)->first(['id', 'source', 'pieces_produced']);
                if ($existing !== null && $existing->source !== 'production_orders') {
                    continue;
                }
                if ($existing === null) {
                    $kept[] = DB::table('production_volumes')->insertGetId($key + ['ulid' => strtoupper((string) Str::ulid()), 'pieces_produced' => (int) $row->pieces,
                        'source' => 'production_orders', 'created_at' => now(), 'updated_at' => now()]);

                    continue;
                }
                $kept[] = $existing->id;
                if ((int) $existing->pieces_produced !== (int) $row->pieces) {
                    DB::table('production_volumes')->where('id', $existing->id)->update(['pieces_produced' => (int) $row->pieces, 'updated_at' => now()]);
                }
            }
            // A month that no finished order falls in any more (an order was reopened or corrected).
            DB::table('production_volumes')->where('company_id', $companyId)->where('source', 'production_orders')->whereNotIn('id', $kept)->delete();
        });
    }
}
