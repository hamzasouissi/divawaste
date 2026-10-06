<?php

namespace App\Modules\Common\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answers in the language of the screen that asked: the application sends its active language in
 * `Accept-Language`. Validation messages and error details follow it; anything else falls back to French.
 */
final class UseRequestLanguage
{
    /** Languages the server has texts for (lang/<code>). */
    public const SUPPORTED = ['fr', 'en'];

    public function handle(Request $request, Closure $next): Response
    {
        $language = $request->getPreferredLanguage(self::SUPPORTED);
        if (is_string($language) && $request->headers->has('Accept-Language')) {
            app()->setLocale($language);
        }

        return $next($request);
    }
}
