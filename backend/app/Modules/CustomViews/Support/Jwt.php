<?php

namespace App\Modules\CustomViews\Support;

use InvalidArgumentException;

/**
 * Compact JWS (HS256) encoding: the only thing DivaWaste needs to vouch for a user to DivaBI. No library for a
 * dozen lines; verification happens on DivaBI's side.
 */
final class Jwt
{
    /** @param  array<string, mixed>  $claims */
    public static function encode(array $claims, string $secret): string
    {
        if (strlen($secret) < 32) {
            throw new InvalidArgumentException('The DivaBI secret must be at least 32 characters long.');
        }
        $segments = [
            self::base64Url((string) json_encode(['alg' => 'HS256', 'typ' => 'JWT'])),
            self::base64Url((string) json_encode($claims, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
        ];
        $segments[] = self::base64Url(hash_hmac('sha256', implode('.', $segments), $secret, true));

        return implode('.', $segments);
    }

    private static function base64Url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
