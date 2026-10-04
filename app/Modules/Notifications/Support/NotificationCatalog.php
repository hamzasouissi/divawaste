<?php

namespace App\Modules\Notifications\Support;

final class NotificationCatalog
{
    public const TYPES = [
        'account.verify_email',
        'account.approved',
        'account.rejected',
        'account.suspended',
        'account.pending_approval',
        'pickup.requested',
        'pickup.confirmed',
        'pickup.collected',
        'pickup.variance_flagged',
        'accreditation.expiring_soon',
        'document.issued',
        'report.ready',
    ];

    public const CHANNELS = ['mail', 'database'];

    public static function isValidType(string $type): bool
    {
        return in_array($type, self::TYPES, true);
    }

    public static function isValidChannel(string $channel): bool
    {
        return in_array($channel, self::CHANNELS, true);
    }
}
