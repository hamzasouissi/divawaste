<?php

namespace App\Modules\Notifications\Support;

/**
 * Business events that notify people (M7-01). Each type has a title and a body in lang/<locale>/notifications.php;
 * `mail` says whether the event is also worth an email. `mail_to: managers` narrows who gets that email by default: the
 * people who manage the limits or the zones of the site. Everyone else who sees the alert reads it in the application,
 * and receives the email only after asking for it in their preferences.
 */
final class NotificationCatalog
{
    /** Whoever holds one of these on the site gets the email of a stock or capacity alert unless they switched it off. */
    public const STOCK_MANAGERS = ['stock.thresholds.manage', 'zones.manage'];

    /** @var array<string, array{mail: bool, mail_to?: 'managers'}> */
    public const TYPES = [
        'stock.threshold_warning' => ['mail' => false],
        'stock.threshold_exceeded' => ['mail' => true, 'mail_to' => 'managers'],
        'stock.threshold_unverifiable' => ['mail' => true, 'mail_to' => 'managers'],
        // What a zone or a site can physically hold: the room of a zone, its load, the weight of the site.
        'capacity.space_warning' => ['mail' => false],
        'capacity.space_unverifiable' => ['mail' => true, 'mail_to' => 'managers'],
        'capacity.space_exceeded' => ['mail' => true, 'mail_to' => 'managers'],
        'capacity.load_warning' => ['mail' => false],
        'capacity.load_unverifiable' => ['mail' => true, 'mail_to' => 'managers'],
        'capacity.load_exceeded' => ['mail' => true, 'mail_to' => 'managers'],
        'capacity.site_warning' => ['mail' => false],
        'capacity.site_unverifiable' => ['mail' => true, 'mail_to' => 'managers'],
        'capacity.site_exceeded' => ['mail' => true, 'mail_to' => 'managers'],
        'pickup.requested' => ['mail' => true],
        'pickup.confirmed' => ['mail' => true],
        'pickup.refused' => ['mail' => true],
        'pickup.cancelled' => ['mail' => true],
        'pickup.collected' => ['mail' => false],
        // What the receiver asks of a collection, no longer met on what left: told to the receiver, and to those who explain the exception.
        'pickup.requirements_unmet_at_loading' => ['mail' => true],
        'pickup.requirement_exception' => ['mail' => true],
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

    /**
     * The permissions whose holders get this email by default, or null when everyone who is told gets it.
     *
     * @return list<string>|null
     */
    public static function mailDefaultPermissions(string $type): ?array
    {
        return (self::TYPES[$type]['mail_to'] ?? null) === 'managers' ? self::STOCK_MANAGERS : null;
    }
}
