<?php

namespace App\Modules\Common\Support;

/**
 * Links sent by email point to the Angular application, never to the API.
 */
final class FrontendUrl
{
    /**
     * @param  array<string, scalar>  $query
     */
    public static function to(string $path, array $query = []): string
    {
        $url = rtrim((string) config('app.frontend_url'), '/').'/'.ltrim($path, '/');

        return $query === [] ? $url : $url.'?'.http_build_query($query);
    }
}
