<?php

namespace App\Modules\Notifications\Support;

/**
 * Business events that notify people (M7-01). Each type has a title and a body in lang/<locale>/notifications.php;
 * `mail` says whether the event is also worth an email by default.
 */
final class NotificationCatalog
{
    /** @var array<string, array{mail: bool}> */
    public const TYPES = [
        'stock.threshold_warning' => ['mail' => false],
        'stock.threshold_exceeded' => ['mail' => true],
        'pickup.requested' => ['mail' => true],
        'pickup.confirmed' => ['mail' => true],
        'pickup.refused' => ['mail' => true],
        'pickup.cancelled' => ['mail' => true],
        'pickup.collected' => ['mail' => false],
        'pickup.received' => ['mail' => false],
        'pickup.variance_flagged' => ['mail' => true],
        'pickup.completed' => ['mail' => false],
        'accreditation.expiring_soon' => ['mail' => true],
        'accreditation.expired' => ['mail' => true],
        'accreditation.reviewed' => ['mail' => true],
        'authorization.usage_high' => ['mail' => true],
        'authorization.expiring_soon' => ['mail' => true],
        'document.signature_requested' => ['mail' => true],
        'document.signed_copy_requested' => ['mail' => false],
        'document.certificate_uploaded' => ['mail' => false],
        'report.ready' => ['mail' => false],
        'report.failed' => ['mail' => false],
        'payment.declared' => ['mail' => true],
        'payment.reviewed' => ['mail' => true],
        'invoice.issued' => ['mail' => true],
        'subscription.activated' => ['mail' => true],
        'subscription.trial_ending' => ['mail' => true],
        'company.pending_review' => ['mail' => true],
    ];

    public const CHANNELS = ['mail', 'database'];

    public static function isValidType(string $type): bool
    {
        return array_key_exists($type, self::TYPES);
    }

    public static function sendsMail(string $type): bool
    {
        return self::TYPES[$type]['mail'] ?? false;
    }
}
