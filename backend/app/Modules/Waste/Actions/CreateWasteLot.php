<?php

namespace App\Modules\Waste\Actions;

use App\Modules\Catalog\Models\WasteType;
use App\Modules\Common\Support\NumberSequence;
use App\Modules\Identity\Models\User;
use App\Modules\Sites\Models\Site;
use App\Modules\Sites\Models\Zone;
use App\Modules\Tenancy\TenantContext;
use App\Modules\Waste\DTOs\CreateWasteLotData;
use App\Modules\Waste\Models\LotTag;
use App\Modules\Waste\Models\WasteLot;
use App\Modules\Waste\Models\WasteLotEvent;
use Illuminate\Support\Facades\DB;

/**
 * M2-01/02: creates a lot with server lot number, QR tag (device ULID by default), composition and 'created' event.
 * Hazard and regulatory code are snapshotted from the waste type.
 */
final class CreateWasteLot
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(CreateWasteLotData $data, User $actor): WasteLot
    {
        return DB::transaction(function () use ($data, $actor) {
            $site = Site::query()->where('ulid', $data->siteUlid)->firstOrFail(['id']);
            $zoneId = Zone::query()->where('ulid', $data->zoneUlid)->where('site_id', $site->id)->valueOrFail('id');
            $type = WasteType::query()->where('ulid', $data->wasteTypeUlid)
                ->firstOrFail(['id', 'is_hazardous', 'regulatory_waste_code_id', 'default_packaging_type_id', 'default_color_family_id', 'default_grammage_gsm', 'default_composition']);
            $year = now()->format('Y');

            $lot = WasteLot::query()->create([
                'ulid' => $data->ulid,
                'lot_number' => NumberSequence::next($this->context->companyId(), 'lot', "L-{$year}-", $year),
                'site_id' => $site->id,
                'zone_id' => $zoneId,
                'waste_type_id' => $type->id,
                'packaging_type_id' => $this->ref('packaging_types', $data->packagingType) ?? $type->default_packaging_type_id,
                'is_hazardous' => $type->is_hazardous,
                'regulatory_waste_code_id' => $type->regulatory_waste_code_id,
                'gross_weight_kg' => $data->grossWeightKg,
                'tare_weight_kg' => $data->tareWeightKg,
                'net_weight_kg' => $data->netWeightKg,
                'quantity' => $data->quantity,
                'unit_id' => $this->ref('units', $data->unit),
                'color_family_id' => $this->ref('color_families', $data->colorFamily) ?? $type->default_color_family_id,
                'color_label' => $data->colorLabel,
                'grammage_gsm' => $data->grammageGsm ?? $type->default_grammage_gsm,
                'extra_attributes' => $data->extraAttributes,
                'notes' => $data->notes,
                'generated_at' => $data->generatedAt ?? now(),
                'created_by_user_id' => $actor->id,
                'created_device_id' => $data->deviceId,
            ]);

            LotComposition::apply($lot, $data->composition ?? $type->default_composition ?? []);
            LotTag::query()->create(['waste_lot_id' => $lot->id, 'tag_type' => 'qr', 'tag_value' => $data->tagValue ?? $lot->ulid,
                'assigned_at' => now(), 'assigned_by_user_id' => $actor->id]);
            WasteLotEvent::record($lot, 'created', [
                'to_status' => $lot->status, 'to_site_id' => $site->id, 'to_zone_id' => $zoneId, 'weight_kg' => $lot->net_weight_kg,
                'gross_weight_kg' => $data->grossWeightKg, 'tare_weight_kg' => $data->tareWeightKg, 'weight_source' => 'manual',
                'source' => $data->source, 'device_id' => $data->deviceId, 'user_id' => $actor->id,
            ]);

            return $lot;
        });
    }

    private function ref(string $table, ?string $code): ?int
    {
        $id = $code === null ? null : DB::table($table)->where('code', $code)->value('id');

        return $id === null ? null : (int) $id;
    }
}
