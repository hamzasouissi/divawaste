<?php

namespace App\Modules\Pickups\Resources;

use App\Modules\Common\Support\ReferenceCodes;
use App\Modules\Pickups\Models\Pickup;
use App\Modules\Pickups\Support\PartyDirectory;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

/**
 * Read by both parties of a pickup. Lines are snapshots taken at request time: a provider never reads
 * the industrial's lots, sites or users directly.
 *
 * @mixin Pickup
 */
class PickupResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $codes = app(ReferenceCodes::class);
        $lines = $this->relationLoaded('lines') ? $this->lines : null;
        $parties = app(PartyDirectory::class);
        $declared = $this->resource->getAttributes()['lines_sum_declared_weight_kg'] ?? null;
        // The lot identifier belongs to the industrial: only the owner of the pickup receives it
        // (to record the loading and open the lot). A provider keeps working with lot numbers.
        $context = app(TenantContext::class);
        $lotUlids = $lines !== null && $context->hasTenant() && $context->companyId() === (int) $this->company_id
            ? DB::table('waste_lots')->whereIn('id', $lines->pluck('waste_lot_id'))->pluck('ulid', 'id')
            : collect();

        return [
            'ulid' => $this->ulid,
            'pickup_number' => $this->pickup_number,
            'status' => $this->status,
            'company' => $parties->company($this->company_id),
            'provider' => $parties->company($this->provider_company_id),
            'transporter' => $parties->company($this->transporter_company_id),
            'site' => $parties->site($this->site_id),
            'requested_date' => $this->requested_date->toDateString(),
            'confirmed_date' => $this->confirmed_date?->toDateString(),
            'collected_at' => $this->collected_at?->toIso8601ZuluString(),
            'received_at' => $this->received_at?->toIso8601ZuluString(),
            'refusal_reason' => $this->refusal_reason,
            'cancellation_reason' => $this->cancellation_reason,
            'notes' => $this->notes,
            'departure_weight_kg' => $this->departure_weight_kg,
            'received_weight_kg' => $this->received_weight_kg,
            'weight_variance_kg' => $this->weight_variance_kg,
            'weight_variance_pct' => $this->weight_variance_pct,
            'variance_flagged' => $this->variance_flagged,
            'variance_acknowledged' => $this->variance_acknowledged_at !== null,
            'variance_comment' => $this->variance_comment,
            'currency_code' => $this->currency_code,
            'version' => $this->version,
            'lots_count' => $this->whenCounted('lines'),
            'declared_weight_kg' => $this->when($declared !== null, fn () => (string) $declared),
            // Signed total: positive = revenue for the industrial, negative = cost.
            'net_amount' => $this->when($lines !== null, fn () => $lines->whereNotNull('net_amount')->isEmpty() ? null
                : $lines->reduce(fn ($sum, $line) => bcadd($sum, (string) ($line->net_amount ?? '0'), 3), '0')),
            'lots' => $this->when($lines !== null, fn () => $lines->map(fn ($line) => [
                'lot' => $lotUlids[$line->waste_lot_id] ?? null,
                'lot_number_snapshot' => $line->lot_number_snapshot,
                'qr_tag_value_snapshot' => $line->qr_tag_value_snapshot,
                'waste_type_label_snapshot' => $line->waste_type_label_snapshot,
                'regulatory_code_snapshot' => $line->regulatory_code_snapshot,
                'is_hazardous' => $line->is_hazardous,
                'line_status' => $line->line_status,
                'declared_weight_kg' => $line->declared_weight_kg,
                'departure_weight_kg' => $line->departure_weight_kg,
                'received_weight_kg' => $line->received_weight_kg,
                'weight_variance_pct' => $line->weight_variance_pct,
                'variance_flagged' => $line->variance_flagged,
                'treatment_channel' => $codes->code('treatment_channels', $line->actual_treatment_channel_id),
                'pricing_mode' => $line->pricing_mode,
                'unit_price' => $line->unit_price,
                'net_amount' => $line->net_amount,
            ])->values()),
        ];
    }
}
