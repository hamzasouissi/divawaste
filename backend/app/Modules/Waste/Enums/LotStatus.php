<?php

namespace App\Modules\Waste\Enums;

/**
 * Lot lifecycle (M2-05, blueprint §6.2).
 */
enum LotStatus: string
{
    case Created = 'created';
    case Stored = 'stored';
    case AwaitingPickup = 'awaiting_pickup';
    case Collected = 'collected';
    case Treated = 'treated';
    case Closed = 'closed';

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, match ($this) {
            self::Created => [self::Stored, self::AwaitingPickup, self::Closed],
            self::Stored => [self::AwaitingPickup, self::Closed],
            self::AwaitingPickup => [self::Stored, self::Collected],
            self::Collected => [self::Treated],
            self::Treated => [self::Closed],
            self::Closed => [],
        }, true);
    }

    /**
     * Lots that can be split or grouped.
     */
    public function isTransformable(): bool
    {
        return $this === self::Created || $this === self::Stored;
    }
}
