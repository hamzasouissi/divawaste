<?php

namespace App\Modules\Common\Support;

use App\Modules\Common\Http\Middleware\UseRequestLanguage;
use Closure;
use Illuminate\Http\Request;

/**
 * Language of a generated document (PDF, Excel). By default the language of the person who asks for it; that person
 * can ask for another one, for a single document (`?lang=`) or as a standing preference of their device
 * (`X-Document-Language`). Numbers follow the language too.
 */
final class DocumentLanguage
{
    public static function of(Request $request): string
    {
        foreach ([$request->query('lang'), $request->input('lang'), $request->header('X-Document-Language')] as $asked) {
            if (is_string($asked) && in_array($asked, UseRequestLanguage::SUPPORTED, true)) {
                return $asked;
            }
        }

        return self::supported(app()->getLocale());
    }

    public static function supported(?string $language): string
    {
        return in_array($language, UseRequestLanguage::SUPPORTED, true) ? (string) $language : 'fr';
    }

    /**
     * Runs the rendering in the given language, then puts the previous one back (a queue worker serves every user).
     *
     * @template T
     *
     * @param  Closure(): T  $render
     * @return T
     */
    public static function during(string $language, Closure $render): mixed
    {
        $previous = app()->getLocale();
        app()->setLocale(self::supported($language));

        try {
            return $render();
        } finally {
            app()->setLocale($previous);
        }
    }

    /**
     * "1 234,5" in French, "1,234.5" in English.
     */
    public static function number(float|int|string|null $value, int $decimals = 3): string
    {
        return app()->getLocale() === 'en'
            ? number_format((float) $value, $decimals, '.', ',')
            : number_format((float) $value, $decimals, ',', ' ');
    }

    /**
     * Same, without the useless zeros: "37,5" rather than "37,500".
     */
    public static function trimmed(float|int|string|null $value, int $decimals = 3): string
    {
        $formatted = self::number($value, $decimals);
        $separator = app()->getLocale() === 'en' ? '.' : ',';

        return str_contains($formatted, $separator) ? rtrim(rtrim($formatted, '0'), $separator) : $formatted;
    }
}
