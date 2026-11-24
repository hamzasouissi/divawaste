<?php

namespace App\Modules\Waste\Support;

use App\Modules\Common\Support\ReferenceCodes;
use App\Modules\Production\Support\CuttingWaste;
use App\Modules\Tenancy\TenantContext;
use App\Modules\Waste\Models\WasteLot;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * What the material of a waste lot cost: the lines of a scrap report (quantity, unit, unit cost, total cost).
 *
 * The price, in this order:
 *  - typed on the lot by a person (material_unit_price, per unit of its material quantity): basis 'entered';
 *  - else a batch of its raw material (lot de matière): the lot's own batch; the batch its OF consumed of that material;
 *    the last batch of the material received by the day the lot was produced. Its price per unit of the material is
 *    (amount + delivery cost) ÷ quantity, its price per kg the same amount ÷ the batch's weight in kg.
 *
 * The value: the lot's material quantity × the price per unit when the quantity is given (as a scrap report counts it:
 * 2.156 m × 3.50), else its net weight × the price per kg. Quantities convert to kilograms as the cutting estimates
 * weigh them (metres × laize × grammage, kilograms as they are, pieces × weight of one). Anything missing gives no cost
 * rather than a wrong one.
 */
final class LotCost
{
    private const NONE = ['material_cost_per_kg' => null, 'material_unit_cost' => null, 'material_value' => null, 'cost_currency_code' => null,
        'cost_batch_id' => null, 'cost_basis' => null];

    /** @var array{materials: array<int, true>, orders: array<int, true>}|null costings put off while deferred() runs */
    private static ?array $deferred = null;

    /** Columns of a lot the cost depends on: a change in any of them costs the lot again. */
    public const INPUTS = ['raw_material_batch_id', 'raw_material_id', 'production_order_id', 'net_weight_kg', 'generated_at',
        'material_quantity', 'material_unit_id', 'material_unit_price'];

    /**
     * @param  object|array<string, mixed>  $lot  company_id, raw_material_batch_id, raw_material_id, production_order_id, net_weight_kg, generated_at,
     *                                            material_quantity, material_unit_id, material_unit_price
     * @return array{material_cost_per_kg: string|null, material_unit_cost: string|null, material_value: string|null, cost_currency_code: string|null, cost_batch_id: int|null, cost_basis: string|null}
     */
    public static function of(object|array $lot): array
    {
        $lot = (object) $lot;
        $int = fn ($value) => $value === null ? null : (int) $value;
        $str = fn ($value) => $value === null || $value === '' ? null : (string) $value;
        $weight = $str($lot->net_weight_kg ?? null);
        $quantity = $str($lot->material_quantity ?? null);
        $typed = $str($lot->material_unit_price ?? null);
        $materialId = $int($lot->raw_material_id ?? null);
        $batchId = $int($lot->raw_material_batch_id ?? null);
        if ($materialId === null && $batchId !== null) {
            $materialId = $int(DB::table('raw_material_batches')->where('id', $batchId)->value('raw_material_id'));
        }
        $material = $materialId === null ? null : DB::table('raw_materials')->where('id', $materialId)->first(['unit_id', 'width_cm', 'grammage_gsm', 'unit_weight_kg']);
        $codes = app(ReferenceCodes::class);
        $materialUnit = $material === null ? null : $codes->code('units', $material->unit_id === null ? null : (int) $material->unit_id);
        // The unit of the lot's quantity and of a typed price: the one given, else the material's own.
        $unit = $codes->code('units', $int($lot->material_unit_id ?? null)) ?? $materialUnit;
        $kgPerUnit = $unit === null ? null : self::positive(CuttingWaste::kilograms('1', $unit, $material?->width_cm, $material?->grammage_gsm, $material?->unit_weight_kg));

        if ($typed !== null) {
            $value = match (true) {
                $quantity !== null => bcmul($quantity, $typed, 3),
                $weight !== null && $kgPerUnit !== null => bcmul(bcdiv($weight, $kgPerUnit, 8), $typed, 3),
                default => null,
            };
            $companyId = $int($lot->company_id ?? null);

            return [
                'material_cost_per_kg' => $kgPerUnit !== null ? bcdiv($typed, $kgPerUnit, 4) : self::perKgOfValue($value, $weight),
                'material_unit_cost' => bcadd($typed, '0', 5),
                'material_value' => $value,
                'cost_currency_code' => $companyId === null ? null : DB::table('companies')->where('id', $companyId)->value('currency_code'),
                'cost_batch_id' => null,
                'cost_basis' => 'entered',
            ];
        }

        [$batchId, $basis] = self::pricingBatch($batchId, $materialId,
            $int($lot->production_order_id ?? null), isset($lot->generated_at) ? substr((string) $lot->generated_at, 0, 10) : null, $int($lot->company_id ?? null));
        if ($batchId === null) {
            return self::NONE;
        }
        $batch = DB::table('raw_material_batches as b')->join('raw_materials as m', 'm.id', '=', 'b.raw_material_id')->where('b.id', $batchId)
            ->first(['b.quantity', 'b.unit_price', 'b.total_amount', 'b.delivery_cost', 'b.currency_code', 'm.unit_id', 'm.width_cm', 'm.grammage_gsm', 'm.unit_weight_kg']);
        if ($batch === null) {
            return self::NONE;
        }
        $perKg = self::perKg($batch);
        // Per unit of the lot's quantity: the batch's own unit as it is, another one through the kilograms.
        $perUnit = match (true) {
            $unit === null || $unit === $materialUnit => self::perUnit($batch),
            $perKg !== null && $kgPerUnit !== null => bcmul($perKg, $kgPerUnit, 5),
            default => null,
        };
        $value = match (true) {
            $quantity !== null && $perUnit !== null => bcmul($quantity, $perUnit, 3),
            $weight !== null && $perKg !== null => bcmul($perKg, $weight, 3),
            default => null,
        };
        if ($perKg === null && $perUnit === null) {
            return self::NONE;
        }

        return [
            'material_cost_per_kg' => $perKg ?? self::perKgOfValue($value, $weight),
            'material_unit_cost' => $perUnit === null ? null : bcadd($perUnit, '0', 5),
            'material_value' => $value,
            'cost_currency_code' => $batch->currency_code,
            'cost_batch_id' => $batchId,
            'cost_basis' => $basis,
        ];
    }

    /**
     * @return array{0: int|null, 1: string|null} batch id and why that one
     */
    private static function pricingBatch(?int $batchId, ?int $materialId, ?int $orderId, ?string $day, ?int $companyId): array
    {
        if ($batchId !== null) {
            return [$batchId, 'lot'];
        }
        if ($materialId === null) {
            return [null, null];
        }
        $priced = fn ($query) => $query->where(fn ($q) => $q->whereNotNull('b.total_amount')->orWhereNotNull('b.unit_price'));
        if ($orderId !== null) {
            $consumed = DB::table('production_order_consumptions as c')->join('raw_material_batches as b', 'b.id', '=', 'c.raw_material_batch_id')
                ->where('c.production_order_id', $orderId)->where('c.raw_material_id', $materialId)->tap($priced)
                ->orderByDesc('c.consumed_on')->orderByDesc('c.id')->value('b.id');
            if ($consumed !== null) {
                return [(int) $consumed, 'consumption'];
            }
        }
        $latest = DB::table('raw_material_batches as b')->where('b.raw_material_id', $materialId)
            ->when($companyId !== null, fn ($q) => $q->where('b.company_id', $companyId))->tap($priced)
            ->when($day !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('b.received_on')->orWhereDate('b.received_on', '<=', $day)))
            ->orderByRaw('b.received_on IS NULL')->orderByDesc('b.received_on')->orderByDesc('b.id')->value('b.id');

        return $latest === null ? [null, null] : [(int) $latest, 'latest'];
    }

    /** The cost of one kilogram of the batch's material, or null when it cannot be known. */
    public static function perKg(object $batch): ?string
    {
        if ($batch->quantity === null || bccomp((string) $batch->quantity, '0', 3) <= 0) {
            return null;
        }
        $amount = $batch->total_amount ?? ($batch->unit_price === null ? null : bcmul((string) $batch->unit_price, (string) $batch->quantity, 6));
        if ($amount === null) {
            return null;
        }
        $amount = bcadd((string) $amount, (string) ($batch->delivery_cost ?? '0'), 6);
        $unit = app(ReferenceCodes::class)->code('units', $batch->unit_id === null ? null : (int) $batch->unit_id);
        $kilograms = CuttingWaste::kilograms((string) $batch->quantity, $unit, $batch->width_cm, $batch->grammage_gsm, $batch->unit_weight_kg);
        if ($kilograms === null || bccomp($kilograms, '0', 8) <= 0) {
            return null;
        }

        return bcdiv($amount, $kilograms, 4);
    }

    /** The cost of one unit of the batch's material (its own unit, delivery included), or null when it cannot be known. */
    public static function perUnit(object $batch): ?string
    {
        if ($batch->quantity === null || bccomp((string) $batch->quantity, '0', 3) <= 0) {
            return null;
        }
        $amount = $batch->total_amount ?? ($batch->unit_price === null ? null : bcmul((string) $batch->unit_price, (string) $batch->quantity, 6));

        return $amount === null ? null : bcdiv(bcadd((string) $amount, (string) ($batch->delivery_cost ?? '0'), 6), (string) $batch->quantity, 5);
    }

    private static function positive(?string $number): ?string
    {
        return $number !== null && bccomp($number, '0', 8) > 0 ? $number : null;
    }

    private static function perKgOfValue(?string $value, ?string $weight): ?string
    {
        return $value === null || $weight === null || bccomp($weight, '0', 3) <= 0 ? null : bcdiv($value, $weight, 4);
    }

    /** Sets the cost of a lot about to be saved. */
    public static function apply(WasteLot $lot): void
    {
        // A new lot is given its company after `saving` (BelongsToCompany stamps it on `creating`): until then, the
        // company it is created in, whose currency a typed price is in.
        $context = app(TenantContext::class);
        $companyId = $lot->company_id ?? ($context->hasTenant() ? $context->companyId() : null);
        $lot->forceFill(self::of([
            'company_id' => $companyId, 'raw_material_batch_id' => $lot->raw_material_batch_id, 'raw_material_id' => $lot->raw_material_id,
            'production_order_id' => $lot->production_order_id, 'net_weight_kg' => $lot->net_weight_kg,
            'generated_at' => $lot->generated_at?->toDateString() ?? now()->toDateString(),
            'material_quantity' => $lot->material_quantity, 'material_unit_id' => $lot->material_unit_id, 'material_unit_price' => $lot->material_unit_price,
        ]));
    }

    /**
     * Prices of raw materials changed (a batch imported, a price or a weight corrected): the lots of those materials
     * are costed again. Written directly, so neither the lots' version (mobile synchronisation) nor their audit trail
     * move for a figure they do not hold themselves.
     *
     * @param  list<int>  $materialIds
     */
    public static function refreshMaterials(array $materialIds): int
    {
        if (self::$deferred !== null) {
            self::$deferred['materials'] += array_fill_keys($materialIds, true);

            return 0;
        }

        return $materialIds === [] ? 0 : self::refresh(fn ($q) => $q->whereIn('raw_material_id', $materialIds)
            ->orWhereIn('raw_material_batch_id', DB::table('raw_material_batches')->whereIn('raw_material_id', $materialIds)->select('id')));
    }

    /**
     * What OFs consumed changed: their lots are costed again, as a lot without its own batch is priced by the batch its
     * OF consumed of its material.
     *
     * @param  list<int>  $orderIds
     */
    public static function refreshOrders(array $orderIds): int
    {
        if (self::$deferred !== null) {
            self::$deferred['orders'] += array_fill_keys($orderIds, true);

            return 0;
        }

        return $orderIds === [] ? 0 : self::refresh(fn ($q) => $q->whereIn('production_order_id', $orderIds));
    }

    /**
     * Runs $work with every costing it asks for (directly or through the batch and material hooks) put off until it
     * ends, then done once per material and per OF: an import of a thousand lines costs each lot once, not once a line.
     * With $flush false (a dry run, rolled back) nothing is costed.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public static function deferred(callable $work, bool $flush = true): mixed
    {
        if (self::$deferred !== null) {
            return $work();
        }
        self::$deferred = ['materials' => [], 'orders' => []];
        try {
            $result = $work();
            $pending = self::$deferred;
        } finally {
            self::$deferred = null;
        }
        if ($flush) {
            self::refreshMaterials(array_keys($pending['materials']));
            self::refreshOrders(array_keys($pending['orders']));
        }

        return $result;
    }

    /** @param  callable(Builder): mixed  $lots  which lots */
    private static function refresh(callable $lots): int
    {
        $count = 0;
        DB::table('waste_lots')->whereNull('deleted_at')->where($lots)
            ->select(['id', 'company_id', 'raw_material_batch_id', 'raw_material_id', 'production_order_id', 'net_weight_kg', 'generated_at',
                'material_quantity', 'material_unit_id', 'material_unit_price'])
            ->chunkById(500, function ($lots) use (&$count) {
                foreach ($lots as $lot) {
                    $count += DB::table('waste_lots')->where('id', $lot->id)->update(self::of($lot));
                }
            });

        return $count;
    }
}
