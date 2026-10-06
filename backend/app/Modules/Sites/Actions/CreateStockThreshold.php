<?php

namespace App\Modules\Sites\Actions;

use App\Modules\Catalog\Models\WasteType;
use App\Modules\Common\Exceptions\BusinessRuleViolation;
use App\Modules\Identity\Models\User;
use App\Modules\Sites\DTOs\CreateStockThresholdData;
use App\Modules\Sites\Models\Site;
use App\Modules\Sites\Models\StockThreshold;
use App\Modules\Sites\Models\Zone;
use Illuminate\Support\Facades\DB;

final class CreateStockThreshold
{
    public function handle(CreateStockThresholdData $data, User $actor): StockThreshold
    {
        $siteId = Site::query()->where('ulid', $data->siteUlid)->value('id');
        $zoneId = $data->zoneUlid === null ? null : Zone::query()->where('ulid', $data->zoneUlid)->value('id');
        $wasteTypeId = $data->wasteTypeUlid === null ? null : WasteType::query()->where('ulid', $data->wasteTypeUlid)->value('id');

        return DB::transaction(function () use ($siteId, $zoneId, $wasteTypeId, $data, $actor) {
            $exists = StockThreshold::query()->where('site_id', $siteId)
                ->where('zone_scope_key', $zoneId ?? 0)->where('waste_type_scope_key', $wasteTypeId ?? 0)->lockForUpdate()->exists();
            if ($exists) {
                throw new BusinessRuleViolation('THRESHOLD_EXISTS', 'A threshold already exists for this site, zone and waste type.');
            }

            return StockThreshold::query()->create([
                'site_id' => $siteId,
                'zone_id' => $zoneId,
                'waste_type_id' => $wasteTypeId,
                'max_quantity_kg' => $data->maxQuantityKg,
                'warning_pct' => $data->warningPct,
                'created_by_user_id' => $actor->id,
            ]);
        });
    }
}
