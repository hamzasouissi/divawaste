<?php

namespace App\Modules\Waste\Resources;

use App\Modules\Waste\Models\WasteLot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

/**
 * @mixin WasteLot
 */
class WasteLotResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'lot_number' => $this->lot_number,
            'status' => $this->status,
            'closed_reason' => $this->closed_reason,
            'origin_type' => $this->origin_type,
            'site' => DB::table('sites')->where('id', $this->site_id)->value('ulid'),
            'zone' => DB::table('zones')->where('id', $this->zone_id)->value('ulid'),
            'waste_type' => DB::table('waste_types')->where('id', $this->waste_type_id)->value('ulid'),
            'is_hazardous' => $this->is_hazardous,
            'net_weight_kg' => $this->net_weight_kg,
            'gross_weight_kg' => $this->gross_weight_kg,
            'tare_weight_kg' => $this->tare_weight_kg,
            'composition_label' => $this->composition_label,
            'qr' => DB::table('lot_tags')->where('waste_lot_id', $this->id)->where('tag_type', 'qr')->where('is_active_flag', true)->value('tag_value'),
            'version' => $this->version,
            'generated_at' => $this->generated_at?->toIso8601ZuluString(),
        ];
    }
}
