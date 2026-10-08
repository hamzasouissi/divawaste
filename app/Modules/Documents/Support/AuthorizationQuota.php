<?php

namespace App\Modules\Documents\Support;

use App\Modules\Common\Exceptions\BusinessRuleViolation;
use App\Modules\Common\Support\Quota;
use App\Modules\Common\Support\ReferenceCodes;
use App\Modules\Documents\Models\WasteAuthorization;
use App\Modules\Notifications\Actions\NotifyUsers;
use App\Modules\Pickups\Enums\PickupStatus;
use App\Modules\Pickups\Models\Pickup;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * How much of an authorisation is used, and whether a pickup is covered. Like the stock, usage is never stored: it is
 * counted from the pickup lines that draw on the authorisation, so a cancelled or refused pickup gives its share back
 * by itself, and a lot left on the dock at loading stops counting.
 *
 * The allowed quantity is read the way the document states it — a weight, a number of lots, lots per packaging — with
 * the same rule as a storage zone: shares of mixed packagings add up, and the highest percentage is the one that counts.
 */
final class AuthorizationQuota
{
    /** A pickup holds its share from the moment it is requested until it is cancelled or refused. */
    private const HOLDING = [PickupStatus::Requested, PickupStatus::Confirmed, PickupStatus::Collected, PickupStatus::Received, PickupStatus::Completed];

    public function __construct(private readonly ReferenceCodes $codes, private readonly NotifyUsers $notify) {}

    /**
     * @param  array{kg: string, lots: int, counts: array<string, int>}|null  $extra  what a pickup being prepared would add
     * @return array{pct: float, uncovered: bool, level: string|null, used: array{kg: string, lots: int, counts: array<string, int>}, remaining: array{kg: string|null, lots: int|null, counts: array<string, int>}}
     */
    public function usage(WasteAuthorization $authorization, ?int $exceptPickupId = null, ?array $extra = null): array
    {
        $used = $this->consumed($authorization->id, $exceptPickupId);
        if ($extra !== null) {
            $used['kg'] = bcadd($used['kg'], $extra['kg'], 3);
            $used['lots'] += $extra['lots'];
            foreach ($extra['counts'] as $kind => $count) {
                $used['counts'][$kind] = ($used['counts'][$kind] ?? 0) + $count;
            }
        }

        $limits = DB::table('waste_authorization_limits')->where('waste_authorization_id', $authorization->id)->orderBy('id')->get(['packaging_type_id', 'max_count'])
            ->mapWithKeys(fn ($row) => [(string) $this->codes->code('packaging_types', (int) $row->packaging_type_id) => (int) $row->max_count])->all();
        $quota = Quota::usage($authorization->max_kg, $limits, $used['kg'], $used['counts']);
        $lotsPct = $authorization->max_lots === null ? null : round($used['lots'] / max(1, $authorization->max_lots) * 100, 1);
        $pct = max($quota['pct'] ?? 0.0, $lotsPct ?? 0.0);

        return [
            'pct' => $pct,
            // A lot whose packaging the authorisation does not mention is covered only if a weight or a number of lots is given too.
            'uncovered' => $quota['uncounted'] > 0 && $authorization->max_kg === null && $authorization->max_lots === null,
            'level' => Quota::level($pct, $authorization->alert_pct),
            'used' => $used,
            'remaining' => [
                'kg' => $quota['remaining']['kg'],
                'lots' => $authorization->max_lots === null ? null : max(0, $authorization->max_lots - $used['lots']),
                'counts' => $quota['remaining']['counts'],
            ],
        ];
    }

    /**
     * Which authorisation covers each waste type of a set of lots on a date, and what it would leave.
     *
     * @param  Collection<int, \stdClass>  $lots  each with waste_type_id, packaging_type_id and kg
     * @return list<array{waste_type_id: int, waste_type: string, authorization: WasteAuthorization|null, reason: string|null, before_pct: float|null, after_pct: float|null}>
     */
    public function cover(Collection $lots, string $date, ?int $exceptPickupId = null): array
    {
        $types = DB::table('waste_types')->whereIn('id', $lots->pluck('waste_type_id')->unique())->where('requires_authorization', true)->orderBy('name')->pluck('name', 'id');
        $cover = [];

        foreach ($types as $typeId => $name) {
            $extra = ['kg' => '0.000', 'lots' => 0, 'counts' => []];
            foreach ($lots->where('waste_type_id', $typeId) as $lot) {
                $kind = (string) $this->codes->code('packaging_types', $lot->packaging_type_id === null ? null : (int) $lot->packaging_type_id);
                $extra['kg'] = bcadd($extra['kg'], (string) $lot->kg, 3);
                $extra['lots']++;
                $extra['counts'][$kind] = ($extra['counts'][$kind] ?? 0) + 1;
            }

            // The one that ends first is used first; a later one is taken when the first has no room left.
            $candidates = WasteAuthorization::query()->where('waste_type_id', $typeId)->where('is_active', true)
                ->whereDate('valid_from', '<=', $date)->whereDate('valid_until', '>=', $date)->orderBy('valid_until')->orderBy('id')->get();
            $choice = ['waste_type_id' => (int) $typeId, 'waste_type' => (string) $name, 'authorization' => null, 'reason' => 'missing', 'before_pct' => null, 'after_pct' => null];

            foreach ($candidates as $candidate) {
                $after = $this->usage($candidate, $exceptPickupId, $extra);
                $reason = $after['uncovered'] ? 'packaging' : ($after['pct'] > 100 ? 'exceeded' : null);
                if ($reason === null || $choice['authorization'] === null) {
                    $choice = ['authorization' => $candidate, 'reason' => $reason, 'before_pct' => $this->usage($candidate, $exceptPickupId)['pct'], 'after_pct' => $after['pct']] + $choice;
                }
                if ($reason === null) {
                    break;
                }
            }
            $cover[] = $choice;
        }

        return $cover;
    }

    /**
     * Ties each line of the pickup to the authorisation that covers it, or refuses the pickup: no waste that needs an
     * authorisation is requested, or confirmed for another date, without one that still has room.
     */
    public function reserve(Pickup $pickup): void
    {
        $date = ($pickup->confirmed_date ?? $pickup->requested_date)->toDateString();
        $lines = DB::table('pickup_lots as pl')->join('waste_lots as l', 'l.id', '=', 'pl.waste_lot_id')
            ->where('pl.pickup_id', $pickup->id)->where('pl.line_status', '!=', 'not_loaded')
            ->get(['pl.id', 'l.waste_type_id', 'l.packaging_type_id', DB::raw('COALESCE(pl.departure_weight_kg, pl.declared_weight_kg) as kg')]);

        foreach ($this->cover($lines, $date, $pickup->id) as $item) {
            if ($item['reason'] !== null) {
                throw new BusinessRuleViolation('AUTHORIZATION_'.strtoupper($item['reason']), match ($item['reason']) {
                    'missing' => "No authorisation covers {$item['waste_type']} on {$date}: record one before requesting this pickup.",
                    'packaging' => "Authorisation {$item['authorization']?->reference} does not cover the packaging of some lots of {$item['waste_type']}.",
                    default => "Authorisation {$item['authorization']?->reference} has not enough room left for {$item['waste_type']}: record a new one.",
                });
            }
            $authorization = $item['authorization'];
            DB::table('pickup_lots')->whereIn('id', $lines->where('waste_type_id', $item['waste_type_id'])->pluck('id'))->update(['waste_authorization_id' => $authorization?->id]);

            if ($authorization !== null && $item['after_pct'] >= $authorization->alert_pct && $authorization->getRawOriginal('notified_usage_at') === null) {
                $this->notify->toCompanyPermission((int) $pickup->company_id, 'pickups.request', 'authorization.usage_high',
                    ['reference' => $authorization->reference, 'waste_type' => $item['waste_type'], 'pct' => (string) round((float) $item['after_pct'])], '/compliance/authorizations');
                DB::table('waste_authorizations')->where('id', $authorization->id)->update(['notified_usage_at' => now()]);
            }
        }
    }

    /**
     * @return array{kg: string, lots: int, counts: array<string, int>}
     */
    private function consumed(int $authorizationId, ?int $exceptPickupId): array
    {
        $rows = DB::table('pickup_lots as pl')->join('pickups as p', 'p.id', '=', 'pl.pickup_id')->join('waste_lots as l', 'l.id', '=', 'pl.waste_lot_id')
            ->where('pl.waste_authorization_id', $authorizationId)->where('pl.line_status', '!=', 'not_loaded')
            ->whereIn('p.status', array_map(fn (PickupStatus $status) => $status->value, self::HOLDING))
            ->when($exceptPickupId !== null, fn ($query) => $query->where('p.id', '!=', $exceptPickupId))
            ->groupBy('l.packaging_type_id')
            ->get(['l.packaging_type_id', DB::raw('COUNT(*) as lots'), DB::raw('SUM(COALESCE(pl.departure_weight_kg, pl.declared_weight_kg)) as kg')]);

        $used = ['kg' => '0.000', 'lots' => 0, 'counts' => []];
        foreach ($rows as $row) {
            $kind = (string) $this->codes->code('packaging_types', $row->packaging_type_id === null ? null : (int) $row->packaging_type_id);
            $used['kg'] = bcadd($used['kg'], (string) $row->kg, 3);
            $used['lots'] += (int) $row->lots;
            $used['counts'][$kind] = ($used['counts'][$kind] ?? 0) + (int) $row->lots;
        }

        return $used;
    }
}
