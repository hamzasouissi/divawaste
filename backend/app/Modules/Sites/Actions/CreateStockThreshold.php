<?php

namespace App\Modules\Sites\Actions;

use App\Modules\Catalog\Models\WasteType;
use App\Modules\Common\Exceptions\BusinessRuleViolation;
use App\Modules\Common\Support\ReferenceCodes;
use App\Modules\Identity\Models\User;
use App\Modules\Sites\DTOs\CreateStockThresholdData;
use App\Modules\Sites\Models\Site;
use App\Modules\Sites\Models\StockThreshold;
use App\Modules\Sites\Models\Zone;
use App\Modules\Sites\Support\StockLimits;
use Illuminate\Support\Facades\DB;

final class CreateStockThreshold
{
    public function __construct(private readonly ReferenceCodes $codes) {}

    public function handle(CreateStockThresholdData $data, User $actor): StockThreshold
    {
        $siteId = Site::query()->where('ulid', $data->siteUlid)->value('id');
        $zoneId = $data->zoneUlid === null ? null : Zone::query()->where('ulid', $data->zoneUlid)->value('id');
        $wasteTypeId = $data->wasteTypeUlid === null ? null : WasteType::query()->where('ulid', $data->wasteTypeUlid)->value('id');

        $packagingId = $this->codes->id('packaging_types', $data->packagingType);
        // A weight and a number of bags are two limits on the same stock; two weights are the same limit twice.
        $measure = StockLimits::measureKey($data->unit, $packagingId);

        return DB::transaction(function () use ($siteId, $zoneId, $wasteTypeId, $packagingId, $measure, $data, $actor) {
            $exists = StockThreshold::query()->where('site_id', $siteId)->where('measure_key', $measure)
                ->where('zone_scope_key', $zoneId ?? 0)->where('waste_type_scope_key', $wasteTypeId ?? 0)->lockForUpdate()->exists();
            if ($exists) {
                throw new BusinessRuleViolation('THRESHOLD_EXISTS', 'A limit in this measure already exists for this site, zone and waste type.');
            }

            return StockThreshold::query()->create([
                'site_id' => $siteId,
                'zone_id' => $zoneId,
                'waste_type_id' => $wasteTypeId,
                'max_quantity' => $data->maxQuantity,
                'unit_code' => $data->unit,
                'packaging_type_id' => $packagingId,
                'measure_key' => $measure,
                'warning_pct' => $data->warningPct,
                'created_by_user_id' => $actor->id,
            ]);
        });
    }
}
