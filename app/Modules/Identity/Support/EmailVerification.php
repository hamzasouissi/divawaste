<?php

namespace App\Modules\Identity\Support;

use App\Modules\Identity\Models\User;

/**
 * Stateless email confirmation link (M0-02): HMAC over user, current email and expiry.
 * Changing the email or reaching the expiry invalidates the link.
 */
final class EmailVerification
{
    public const VALIDITY_HOURS = 24;

    /**
     * @return array{user: string, expires: int, signature: string}
     */
    public static function parameters(User $user): array
    {
        $expires = now()->addHours(self::VALIDITY_HOURS)->getTimestamp();

        return ['user' => $user->ulid, 'expires' => $expires, 'signature' => self::sign($user, $expires)];
    }

    public static function isValid(User $user, int $expires, string $signature): bool
    {
        return $expires >= now()->getTimestamp() && hash_equals(self::sign($user, $expires), $signature);
    }

    private static function sign(User $user, int $expires): string
    {
        return hash_hmac('sha256', "verify-email|{$user->ulid}|{$user->email}|{$expires}", (string) config('app.key'));
    }
}
