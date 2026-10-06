<?php

namespace App\Modules\Common\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Correlation id for logs, jobs (via Context) and error responses.
 */
final class AssignRequestId
{
    public const HEADER = 'X-Request-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $incoming = (string) $request->headers->get(self::HEADER, '');
        $requestId = preg_match('/^[A-Za-z0-9._-]{8,64}$/', $incoming) === 1 ? $incoming : (string) Str::ulid();

        $request->headers->set(self::HEADER, $requestId);
        Context::add('request_id', $requestId);

        $response = $next($request);
        $response->headers->set(self::HEADER, $requestId);

        return $response;
    }
}
