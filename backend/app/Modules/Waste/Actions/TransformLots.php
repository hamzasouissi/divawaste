<?php

namespace App\Modules\Waste\Actions;

use App\Modules\Common\Exceptions\BusinessRuleViolation;
use App\Modules\Common\Support\NumberSequence;
use App\Modules\Identity\Models\User;
use App\Modules\Sites\Models\Zone;
use App\Modules\Tenancy\TenantContext;
use App\Modules\Waste\DTOs\GroupWasteLotsData;
use App\Modules\Waste\DTOs\SplitWasteLotData;
use App\Modules\Waste\Enums\LotStatus;
use App\Modules\Waste\Models\LotTag;
use App\Modules\Waste\Models\WasteLot;
use App\Modules\Waste\Models\WasteLotEvent;
use App\Modules\Waste\Models\WasteLotLineage;
use App\Modules\Waste\Models\WasteLotOperation;
use Illuminate\Support\Facades\DB;

/**
 * Split / grouping (M2-06, blueprint §6.6): inputs are closed (never mutated otherwise), outputs are new lots
 * with new QR tags, and every input → output edge is recorded in the lineage.
 */
final class TransformLots
{
    private const SPLIT_TOLERANCE_PCT = '2';

    public function __construct(private readonly TenantContext $context, private readonly TransitionLot $transition) {}

    /**
     * @return list<WasteLot> outputs
     */
    public function split(WasteLot $lot, SplitWasteLotData $data, User $actor): array
    {
        return DB::transaction(function () use ($lot, $data, $actor) {
            $lot = WasteLot::query()->whereKey($lot->id)->lockForUpdate()->firstOrFail();
            $this->assertTransformable($lot);

            $outputTotal = array_reduce($data->outputs, fn ($sum, $o) => bcadd($sum, (string) $o['net_weight_kg'], 3), '0');
            $input = (string) $lot->net_weight_kg;
            if (bccomp($input, '0', 3) > 0 && $data->notes === null
                && bccomp(bcdiv(bcmul(ltrim(bcsub($outputTotal, $input, 3), '-'), '100', 6), $input, 6), self::SPLIT_TOLERANCE_PCT, 6) > 0) {
                throw new BusinessRuleViolation('SPLIT_WEIGHT_GAP', 'A weight difference above 2 % requires a comment.');
            }

            $operation = $this->operation('split', $lot->site_id, $input, $outputTotal, $data->notes, $actor);
            $composition = $this->compositionOf($lot);
            $outputs = [];
            foreach ($data->outputs as $output) {
                $zoneId = $output['zone'] === null ? $lot->zone_id
                    : Zone::query()->where('ulid', $output['zone'])->where('site_id', $lot->site_id)->valueOrFail('id');
                $child = $this->derive($lot, 'split', (string) $output['net_weight_kg'], (int) $zoneId, $actor, $composition);
                $this->link($operation, $lot, $child, 'split', (string) $output['net_weight_kg'], $actor);
                $outputs[] = $child;
            }

            $this->transition->handle($lot, LotStatus::Closed, $actor, closedReason: 'split');

            return $outputs;
        });
    }

    public function group(GroupWasteLotsData $data, User $actor): WasteLot
    {
        return DB::transaction(function () use ($data, $actor) {
            $inputs = WasteLot::query()->whereIn('ulid', $data->lotUlids)->orderBy('id')->lockForUpdate()->get();
            if ($inputs->count() < 2 || $inputs->count() !== count(array_unique($data->lotUlids))) {
                throw new BusinessRuleViolation('GROUP_INVALID_INPUTS', 'Grouping needs at least two existing lots.');
            }

            $first = $inputs->first();
            foreach ($inputs as $input) {
                $this->assertTransformable($input);
                if ($input->site_id !== $first->site_id || $input->waste_type_id !== $first->waste_type_id || $input->is_hazardous !== $first->is_hazardous) {
                    throw new BusinessRuleViolation('GROUP_MIXED_LOTS', 'Grouped lots must share site, waste type and hazard status.');
                }
            }

            $zoneId = (int) Zone::query()->where('ulid', $data->zoneUlid)->where('site_id', $first->site_id)->valueOrFail('id');
            $total = $inputs->reduce(fn ($sum, WasteLot $l) => bcadd($sum, (string) $l->net_weight_kg, 3), '0');
            $operation = $this->operation('grouping', $first->site_id, $total, $total, $data->notes, $actor);

            $group = $this->derive($first, 'grouping', $total, $zoneId, $actor, LotComposition::weightedAverage($inputs->all(), $total));
            $group = $this->transition->handle($group, LotStatus::Stored, $actor);

            foreach ($inputs as $input) {
                $this->link($operation, $input, $group, 'grouping', (string) $input->net_weight_kg, $actor);
                $this->transition->handle($input, LotStatus::Closed, $actor, closedReason: 'grouped');
            }

            return $group;
        });
    }

    private function assertTransformable(WasteLot $lot): void
    {
        if (! $lot->status->isTransformable() || $lot->trashed()) {
            throw new BusinessRuleViolation('LOT_NOT_TRANSFORMABLE', "Lot {$lot->lot_number} cannot be split or grouped.");
        }
    }

    private function operation(string $type, int $siteId, string $input, string $output, ?string $notes, User $actor): WasteLotOperation
    {
        return WasteLotOperation::query()->create([
            'operation_type' => $type, 'site_id' => $siteId, 'input_total_kg' => $input, 'output_total_kg' => $output,
            'notes' => $notes, 'performed_by_user_id' => $actor->id, 'performed_at' => now(), 'recorded_at' => now(),
        ]);
    }

    /**
     * @param  list<array{material: string, pct: string}>  $composition
     */
    private function derive(WasteLot $template, string $origin, string $netKg, int $zoneId, User $actor, array $composition): WasteLot
    {
        $year = now()->format('Y');
        $lot = WasteLot::query()->create([
            'lot_number' => NumberSequence::next($this->context->companyId(), 'lot', "L-{$year}-", $year),
            'origin_type' => $origin,
            'net_weight_kg' => $netKg,
            'zone_id' => $zoneId,
            'generated_at' => now(),
            'created_by_user_id' => $actor->id,
        ] + $template->only(['site_id', 'waste_type_id', 'packaging_type_id', 'is_hazardous', 'regulatory_waste_code_id',
            'color_family_id', 'color_label', 'grammage_gsm', 'extra_attributes']));

        LotComposition::apply($lot, $composition);
        LotTag::query()->create(['waste_lot_id' => $lot->id, 'tag_type' => 'qr', 'tag_value' => $lot->ulid, 'assigned_at' => now(), 'assigned_by_user_id' => $actor->id]);
        WasteLotEvent::record($lot, 'created', ['to_status' => LotStatus::Created, 'to_site_id' => $lot->site_id, 'to_zone_id' => $zoneId,
            'weight_kg' => $netKg, 'weight_source' => 'computed', 'user_id' => $actor->id]);

        return $lot;
    }

    private function link(WasteLotOperation $operation, WasteLot $parent, WasteLot $child, string $relation, string $kg, User $actor): void
    {
        WasteLotLineage::query()->create(['waste_lot_operation_id' => $operation->id, 'parent_lot_id' => $parent->id,
            'child_lot_id' => $child->id, 'relation_type' => $relation, 'quantity_kg' => $kg, 'recorded_at' => now()]);

        $into = $relation === 'split' ? 'split_into' : 'grouped_into';
        $from = $relation === 'split' ? 'split_from' : 'grouped_from';
        WasteLotEvent::record($parent, $into, ['waste_lot_operation_id' => $operation->id, 'payload' => ['lot' => $child->ulid], 'user_id' => $actor->id]);
        WasteLotEvent::record($child, $from, ['waste_lot_operation_id' => $operation->id, 'payload' => ['lot' => $parent->ulid], 'user_id' => $actor->id]);
    }

    /**
     * @return list<array{material: string, pct: string}>
     */
    private function compositionOf(WasteLot $lot): array
    {
        return DB::table('waste_lot_compositions as c')->join('materials as m', 'm.id', '=', 'c.material_id')
            ->where('c.waste_lot_id', $lot->id)->get(['m.code', 'c.percentage'])
            ->map(fn ($row) => ['material' => (string) $row->code, 'pct' => (string) $row->percentage])->all();
    }
}
