<?php

namespace App\Modules\Marketplace\Support;

use App\Modules\Common\Support\ReferenceCodes;
use App\Modules\Pickups\Models\Pickup;
use App\Modules\Waste\Actions\LotComposition;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What both sides of the bourse see of a listing's lots, what a proposal was made on, and what an accepted deal costs.
 * Raw reads on purpose: the buyer reads the seller's lots through the listing it may bid on, never through the lot model.
 */
final class MarketplaceDeal
{
    /** What a buyer is shown of a lot: what the waste is, never where it stands in the seller's workshop. */
    private const LOT_COLUMNS = ['l.id', 'l.lot_number', 'l.status', 'l.is_hazardous', 'l.net_weight_kg', 'l.weight_basis', 'l.quantity', 'l.unit_id',
        'l.packaging_type_id', 'l.package_count', 'l.color_family_id', 'l.color_label', 'l.grammage_gsm', 'l.composition_label', 'l.composition_source', 'l.regulatory_waste_code_id'];

    /**
     * The lots of a listing as the bourse shows them, oldest first.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function lots(int $listingId): Collection
    {
        $codes = app(ReferenceCodes::class);
        $rows = DB::table('marketplace_listing_lots as ml')->join('waste_lots as l', 'l.id', '=', 'ml.waste_lot_id')
            ->where('ml.marketplace_listing_id', $listingId)->orderBy('l.id')->get(self::LOT_COLUMNS);

        return $rows->map(fn ($lot) => [
            'lot_number' => $lot->lot_number,
            'status' => $lot->status,
            'is_hazardous' => (bool) $lot->is_hazardous,
            'regulatory_code' => $codes->code('regulatory_waste_codes', $lot->regulatory_waste_code_id === null ? null : (int) $lot->regulatory_waste_code_id),
            'net_weight_kg' => $lot->net_weight_kg,
            'weight_basis' => $lot->net_weight_kg === null ? 'unknown' : ($lot->weight_basis ?? 'measured'),
            'quantity' => $lot->quantity,
            'unit' => $codes->code('units', $lot->unit_id === null ? null : (int) $lot->unit_id),
            'packaging_type' => $codes->code('packaging_types', $lot->packaging_type_id === null ? null : (int) $lot->packaging_type_id),
            'package_count' => $lot->package_count,
            'color_family' => $codes->code('color_families', $lot->color_family_id === null ? null : (int) $lot->color_family_id),
            'color_label' => $lot->color_label,
            'grammage_gsm' => $lot->grammage_gsm,
            'composition' => LotComposition::of((int) $lot->id),
            'composition_source' => $lot->composition_label === null ? null : $lot->composition_source,
        ])->values();
    }

    /**
     * How much the listing weighs: the sum of the known weights, the lots nobody weighed counted apart (never as 0 kg),
     * and the quantities of waste counted in pieces or litres, per unit.
     *
     * @param  Collection<int, array<string, mixed>>  $lots
     * @return array{weight_kg: string|null, unweighed_lots: int, quantities: array<string, string>, lots_count: int}
     */
    public static function totals(Collection $lots): array
    {
        $known = $lots->filter(fn (array $lot) => $lot['net_weight_kg'] !== null);
        $quantities = [];
        foreach ($lots->filter(fn (array $lot) => $lot['unit'] !== null && ! in_array($lot['unit'], ['kg', 't'], true) && $lot['quantity'] !== null) as $lot) {
            $quantities[$lot['unit']] = bcadd($quantities[$lot['unit']] ?? '0', (string) $lot['quantity'], 3);
        }

        return [
            'weight_kg' => $known->isEmpty() ? null : $known->reduce(fn (string $sum, array $lot) => bcadd($sum, (string) $lot['net_weight_kg'], 3), '0'),
            'unweighed_lots' => $lots->count() - $known->count(),
            'quantities' => $quantities,
            'lots_count' => $lots->count(),
        ];
    }

    /**
     * What the listing is made of as a whole: the average of its lots weighted by their weight — only when every lot
     * is weighed and has a composition. Otherwise null: an average over part of the lots would describe something else.
     *
     * @param  Collection<int, array<string, mixed>>  $lots
     * @return list<array{material: string, pct: string}>|null
     */
    public static function composition(Collection $lots): ?array
    {
        if ($lots->isEmpty() || $lots->contains(fn (array $lot) => $lot['net_weight_kg'] === null || $lot['composition'] === [])) {
            return null;
        }
        $total = $lots->reduce(fn (string $sum, array $lot) => bcadd($sum, (string) $lot['net_weight_kg'], 3), '0');
        if (bccomp($total, '0', 3) === 0) {
            return null;
        }
        $mass = [];
        foreach ($lots as $lot) {
            foreach ($lot['composition'] as $line) {
                $mass[$line['material']] = bcadd($mass[$line['material']] ?? '0', bcmul((string) $lot['net_weight_kg'], $line['pct'], 6), 6);
            }
        }
        $lines = [];
        foreach ($mass as $material => $value) {
            $lines[] = ['material' => (string) $material, 'pct' => bcdiv($value, $total, 2)];
        }
        usort($lines, fn (array $a, array $b) => bccomp($b['pct'], $a['pct'], 2));

        return $lines;
    }

    /**
     * What the lots of the listing are now, as one hash. A proposal records it; accepting a proposal made on lots that
     * changed since (weighed again, recounted, composition corrected) is refused: the price was set for other lots.
     */
    public static function fingerprint(int $listingId): string
    {
        $lots = DB::table('marketplace_listing_lots as ml')->join('waste_lots as l', 'l.id', '=', 'ml.waste_lot_id')
            ->where('ml.marketplace_listing_id', $listingId)->orderBy('l.id')
            ->get(['l.id', 'l.lot_number', 'l.net_weight_kg', 'l.weight_basis', 'l.quantity', 'l.unit_id', 'l.packaging_type_id', 'l.package_count',
                'l.color_family_id', 'l.color_label', 'l.grammage_gsm', 'l.composition_label', 'l.is_hazardous', 'l.deleted_at'])
            ->map(fn ($lot) => array_map(fn ($value) => $value === null ? null : (string) $value, (array) $lot))->all();

        return hash('sha256', (string) json_encode($lots));
    }

    /**
     * The amount of a price on a weight, signed for the seller: positive = it receives, negative = it pays. Null when
     * the price is by weight and the weight is not known.
     */
    public static function amount(string $pricingMode, string $unitPrice, string $direction, ?string $weightKg): ?string
    {
        $amount = match ($pricingMode) {
            'per_kg' => $weightKg === null ? null : bcmul($weightKg, $unitPrice, 3),
            'per_tonne' => $weightKg === null ? null : bcdiv(bcmul($weightKg, $unitPrice, 6), '1000', 3),
            default => bcadd($unitPrice, '0', 3),
        };

        return $amount === null ? null : ($direction === 'cost' ? bcsub('0', $amount, 3) : $amount);
    }

    /**
     * The terms of the accepted deal a pickup carries out, for whoever reads that pickup (both sides agreed on them).
     *
     * @return array<string, mixed>|null
     */
    public static function summary(int $negotiationId): ?array
    {
        $deal = DB::table('marketplace_negotiations as n')->join('marketplace_listings as ml', 'ml.id', '=', 'n.marketplace_listing_id')
            ->join('marketplace_proposals as p', 'p.id', '=', 'n.accepted_proposal_id')->where('n.id', $negotiationId)
            ->first(['n.ulid', 'n.commission_pct', 'n.accepted_at', 'ml.ulid as listing', 'ml.listing_number', 'p.pricing_mode', 'p.unit_price', 'p.direction', 'p.currency_code', 'p.pickup_date']);

        return $deal === null ? null : [
            'negotiation' => $deal->ulid, 'listing' => $deal->listing, 'listing_number' => $deal->listing_number,
            'pricing_mode' => $deal->pricing_mode, 'unit_price' => (string) $deal->unit_price, 'direction' => $deal->direction,
            'currency_code' => $deal->currency_code, 'pickup_date' => $deal->pickup_date, 'commission_pct' => $deal->commission_pct === null ? null : (string) $deal->commission_pct,
            'accepted_at' => $deal->accepted_at === null ? null : now()->parse($deal->accepted_at, 'UTC')->toIso8601ZuluString(),
        ];
    }

    /**
     * The prices of the received lines of a pickup made by a deal, in the form PickupWorkflow::price takes them. A price
     * by weight goes to every line that has a measured weight (the others wait to be priced by hand: an unknown weight
     * is not 0 kg); a flat amount is shared out evenly between the lines that left, the rounding on the first one.
     *
     * @return array<string, array{direction: string, pricing_mode: string, unit_price: string}>
     */
    public static function agreedPrices(Pickup $pickup): array
    {
        $deal = $pickup->marketplace_negotiation_id === null ? null : DB::table('marketplace_negotiations as n')
            ->join('marketplace_proposals as p', 'p.id', '=', 'n.accepted_proposal_id')
            ->where('n.id', $pickup->marketplace_negotiation_id)->first(['p.pricing_mode', 'p.unit_price', 'p.direction']);
        if ($deal === null) {
            return [];
        }
        $lines = DB::table('pickup_lots')->where('pickup_id', $pickup->id)->whereIn('line_status', ['loaded', 'received', 'treated'])->orderBy('id')
            ->get(['lot_number_snapshot', 'received_weight_kg', 'departure_weight_kg', 'weight_basis']);
        if ($deal->pricing_mode !== 'flat') {
            return $lines->filter(fn ($line) => $line->received_weight_kg !== null || ($line->departure_weight_kg !== null && $line->weight_basis !== 'estimated'))
                ->mapWithKeys(fn ($line) => [$line->lot_number_snapshot => ['direction' => $deal->direction, 'pricing_mode' => $deal->pricing_mode, 'unit_price' => (string) $deal->unit_price]])->all();
        }
        if ($lines->isEmpty()) {
            return [];
        }
        $share = bcdiv((string) $deal->unit_price, (string) $lines->count(), 3);
        $first = bcsub((string) $deal->unit_price, bcmul($share, (string) ($lines->count() - 1), 3), 3);

        return $lines->values()->mapWithKeys(fn ($line, int $index) => [$line->lot_number_snapshot => ['direction' => $deal->direction, 'pricing_mode' => 'flat', 'unit_price' => $index === 0 ? $first : $share]])->all();
    }
}
