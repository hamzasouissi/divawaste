<?php

namespace App\Modules\Waste\Actions;

use App\Modules\Common\Exceptions\BusinessRuleViolation;
use App\Modules\Identity\Models\User;
use App\Modules\Waste\Enums\LotStatus;
use App\Modules\Waste\Models\WasteLot;
use App\Modules\Waste\Models\WasteLotEvent;
use Illuminate\Support\Facades\DB;

/**
 * Single entry point for lot status changes (M2-05): validated transition, timestamp, version, event.
 */
final class TransitionLot
{
    private const TIMESTAMPS = [
        'stored' => 'stored_at', 'awaiting_pickup' => 'awaiting_pickup_at', 'collected' => 'collected_at',
        'treated' => 'treated_at', 'closed' => 'closed_at',
    ];

    public function handle(WasteLot $lot, LotStatus $to, User $actor, ?int $zoneId = null, ?string $closedReason = null): WasteLot
    {
        return DB::transaction(function () use ($lot, $to, $actor, $zoneId, $closedReason) {
            $lot = WasteLot::query()->whereKey($lot->id)->lockForUpdate()->firstOrFail();
            if (! $lot->status->canTransitionTo($to)) {
                throw new BusinessRuleViolation('INVALID_TRANSITION', "Lot cannot go from {$lot->status->value} to {$to->value}.");
            }

            $from = $lot->status;
            $fromZone = $lot->zone_id;
            $lot->status = $to;
            $lot->{self::TIMESTAMPS[$to->value]} = now();
            $lot->closed_reason = $to === LotStatus::Closed ? ($closedReason ?? 'completed') : null;
            $lot->zone_id = $zoneId ?? $lot->zone_id;
            $lot->updated_by_user_id = $actor->id;
            $lot->save();

            WasteLotEvent::record($lot, 'status_changed', [
                'from_status' => $from, 'to_status' => $to, 'from_zone_id' => $fromZone, 'to_zone_id' => $lot->zone_id,
                'user_id' => $actor->id,
            ]);

            return $lot;
        });
    }
}
