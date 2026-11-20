<?php

namespace App\Modules\Waste\Resources;

use App\Modules\Common\Support\ReferenceCodes;
use App\Modules\Production\Support\LotOrigin;
use App\Modules\Waste\Models\WasteLot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

/**
 * @mixin WasteLot
 */
class WasteLotResource extends JsonResource
{
    /** Relations the resource reads: eager load them on collections (strict models forbid lazy loading). */
    public const RELATIONS = ['site:id,ulid,code,name', 'zone:id,ulid,code,name', 'wasteType:id,ulid,code,name', 'activeQrTag:id,waste_lot_id,tag_value,print_count', 'scrapReason:id,ulid,code,label,category,color'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing(self::RELATIONS);
        $named = fn ($model) => $model === null ? null : ['ulid' => $model->ulid, 'code' => $model->code, 'name' => $model->name];

        return [
            'ulid' => $this->ulid,
            'lot_number' => $this->lot_number,
            'status' => $this->status,
            'closed_reason' => $this->closed_reason,
            'origin_type' => $this->origin_type,
            'site' => $named($this->site),
            'zone' => $named($this->zone),
            'waste_type' => $named($this->wasteType),
            'is_hazardous' => $this->is_hazardous,
            'regulatory_code' => self::code('regulatory_waste_codes', $this->regulatory_waste_code_id),
            'packaging_type' => self::code('packaging_types', $this->packaging_type_id),
            // How many packages the lot is made of; null for loose waste. Not confirmed = the default of one, nobody counted.
            'package_count' => $this->package_count,
            'package_count_confirmed' => (bool) $this->package_count_confirmed,
            'net_weight_kg' => $this->net_weight_kg,
            // null = nobody weighed the lot yet. 'measured' on a scale, 'computed' from the quantity, 'estimated' by the person, 'unknown'.
            'weight_basis' => $this->weight_basis ?? 'measured',
            'gross_weight_kg' => $this->gross_weight_kg,
            'tare_weight_kg' => $this->tare_weight_kg,
            'quantity' => $this->quantity,
            'unit' => self::code('units', $this->unit_id),
            'color_family' => self::code('color_families', $this->color_family_id),
            'color_label' => $this->color_label,
            'grammage_gsm' => $this->grammage_gsm,
            'composition_label' => $this->composition_label,
            // Where the composition comes from: 'entered' by a person, 'raw_material', 'waste_type' (defaults, to be
            // checked), 'grouping' (average of grouped lots), 'split' (kept from the lot split); null = not known.
            'composition_source' => $this->composition_label === null ? null : $this->composition_source,
            'notes' => $this->notes,
            // Why the lot was scrapped: a reason of the company's list (UE24 "Trous dans le tissu"), and the lot's own note.
            'scrap_reason' => $this->scrap_reason_id === null ? null : $this->scrapReason?->summary(),
            'scrap_note' => $this->scrap_note,
            // What its material cost, from the batch it was cut from — production costs, for those who may see them.
            // basis: 'lot' (its own batch), 'consumption' (the batch its OF consumed), 'latest' (last batch received by its date).
            'material_cost' => $request->user()?->can('production.finance.view') ? [
                'per_kg' => $this->material_cost_per_kg,
                'value' => $this->material_value,
                'currency' => $this->cost_currency_code,
                'basis' => $this->cost_basis,
                'batch' => $this->cost_batch_id === null ? null : (array) DB::table('raw_material_batches')->where('id', $this->cost_batch_id)->first(['ulid', 'batch_number', 'received_on']),
            ] : null,
            // Where the waste comes from in production: OF, order, client, article, stage, raw material, supplier (null when unknown).
            'origin' => app(LotOrigin::class)->describe($this->resource),
            'qr' => $this->activeQrTag?->tag_value,
            'label_print_count' => (int) ($this->activeQrTag->print_count ?? 0),
            'version' => $this->version,
            'generated_at' => $this->generated_at->toIso8601ZuluString(),
            'stored_at' => $this->stored_at?->toIso8601ZuluString(),
            'awaiting_pickup_at' => $this->awaiting_pickup_at?->toIso8601ZuluString(),
            'collected_at' => $this->collected_at?->toIso8601ZuluString(),
            'treated_at' => $this->treated_at?->toIso8601ZuluString(),
            'closed_at' => $this->closed_at?->toIso8601ZuluString(),
        ];
    }

    private static function code(string $table, ?int $id): ?string
    {
        return app(ReferenceCodes::class)->code($table, $id);
    }
}
