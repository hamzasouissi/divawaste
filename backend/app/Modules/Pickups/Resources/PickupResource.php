<?php

namespace App\Modules\Pickups\Resources;

use App\Modules\Common\Support\ReferenceCodes;
use App\Modules\Pickups\Models\Pickup;
use App\Modules\Pickups\Support\PartyDirectory;
use App\Modules\Pickups\Support\PickupCompliance;
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
        $compliance = $lines !== null ? PickupCompliance::of($this->resource) : null;
        $declared = $this->resource->getAttributes()['lines_sum_declared_weight_kg'] ?? null;
        // Counted from the lines when they are loaded (one pickup), by the query otherwise (a list).
        $unweighed = [
            'departure' => $lines?->filter(fn ($line) => $line->loaded_at !== null && $line->departure_weight_kg === null)->count() ?? $this->resource->getAttributes()['departure_unweighed_lots'] ?? null,
            'received' => $lines?->filter(fn ($line) => $line->received_at !== null && $line->received_weight_kg === null)->count() ?? $this->resource->getAttributes()['received_unweighed_lots'] ?? null,
        ];
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
            // The receiver, then the transporter when it is someone else. `external` marks a partner without an account:
            // the industrial then records each stage itself.
            'provider' => $parties->company($this->provider_company_id) ?? $parties->external($this->external_provider_id),
            'transporter' => $parties->company($this->transporter_company_id) ?? $parties->external($this->external_transporter_id),
            'site' => $parties->site($this->site_id),
            'requested_date' => $this->requested_date->toDateString(),
            'confirmed_date' => $this->confirmed_date?->toDateString(),
            'collected_at' => $this->collected_at?->toIso8601ZuluString(),
            'received_at' => $this->received_at?->toIso8601ZuluString(),
            'refusal_reason' => $this->refusal_reason,
            'cancellation_reason' => $this->cancellation_reason,
            'notes' => $this->notes,
            // What the receiver asked of this collection when it was requested, the figures and the answer — and, when it
            // was sent although a blocking requirement failed, who decided it and why. Null when it asked for nothing.
            'provider_requirements' => $this->provider_requirements,
            // The same requirements judged again on what was actually loaded, beside the first answer (which is not
            // changed): figures of departure, lots left on site, and — when a blocking one was no longer met — the
            // exception and who explained it. Null when the receiver asks for nothing, or before loading.
            'loading_requirements' => $this->loading_requirements,
            // The documents of the pickup, apart from its status: closing a pickup does not make its slips complete.
            'compliance' => $this->when($compliance !== null, $compliance),
            'compliance_state' => $compliance['state'] ?? PickupCompliance::fromCounts($this->resource),
            'departure_weight_kg' => $this->departure_weight_kg,
            'received_weight_kg' => $this->received_weight_kg,
            'weight_variance_kg' => $this->weight_variance_kg,
            'weight_variance_pct' => $this->weight_variance_pct,
            'variance_flagged' => $this->variance_flagged,
            // The worst outcome among the lots: within, warning, blocking, no_reference (nothing to compare with).
            'tolerance_status' => $this->tolerance_status,
            'variance_acknowledged' => $this->variance_acknowledged_at !== null,
            // 'acknowledged' = explained; 'authorized' = a gap above the blocking limit accepted by someone entitled to.
            'variance_decision' => $this->variance_decision,
            'variance_comment' => $this->variance_comment,
            'currency_code' => $this->currency_code,
            'version' => $this->version,
            'lots_count' => $this->whenCounted('lines'),
            // Lots whose weight is not known: every weight of this pickup is the sum of the others, never of zeros.
            'unweighed_lots' => $this->when($lines !== null, fn () => $lines->filter(fn ($line) => ($line->received_weight_kg ?? $line->departure_weight_kg ?? $line->declared_weight_kg) === null)->count()),
            // The same, stage by stage: lots that left without a weight, lots received without one. The departure and
            // received totals above do not include them, and are empty when no lot has a weight at that stage.
            'departure_unweighed_lots' => $this->when($unweighed['departure'] !== null, $unweighed['departure']),
            'received_unweighed_lots' => $this->when($unweighed['received'] !== null, $unweighed['received']),
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
                // 'estimated': the weight the lot left with is a guess, not a measure; null on older lines.
                'weight_basis' => $line->weight_basis,
                'departure_weight_kg' => $line->departure_weight_kg,
                'received_weight_kg' => $line->received_weight_kg,
                'weight_variance_pct' => $line->weight_variance_pct,
                'variance_flagged' => $line->variance_flagged,
                // What the lot is compared in (mass, count, volume) and its quantities in that unit; null for weighed waste.
                'measure_dimension' => $line->measure_dimension,
                'unit' => $line->unit_code ?? 'kg',
                'declared_quantity' => $line->declared_quantity,
                'departure_quantity' => $line->departure_quantity,
                'received_quantity' => $line->received_quantity,
                // How the lot is packed and how many packages it was requested and loaded with; a count corrected after
                // departure stands beside it, the original is not replaced.
                'packaging_type' => $line->packaging_code,
                'package_count' => $line->package_count,
                'corrected_package_count' => $line->corrected_package_count,
                'planned_channel' => $codes->code('treatment_channels', $line->planned_treatment_channel_id),
                'tolerance_status' => $line->tolerance_status,
                // The rule applied and the figures it was applied to, as they stood at reception.
                'tolerance' => $line->tolerance_rule,
                'treatment_channel' => $codes->code('treatment_channels', $line->actual_treatment_channel_id),
                'pricing_mode' => $line->pricing_mode,
                'unit_price' => $line->unit_price,
                'net_amount' => $line->net_amount,
            ])->values()),
        ];
    }
}
