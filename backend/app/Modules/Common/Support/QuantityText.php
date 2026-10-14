<?php

namespace App\Modules\Common\Support;

use Illuminate\Support\Facades\DB;

/**
 * Figures as a message prints them: a number written the way the language writes it, followed by its unit. A message is
 * read by people who do not share a language, so each figure is prepared in every language the application speaks and
 * the reader's one is used (see `localized`).
 */
final class QuantityText
{
    public const LOCALES = ['fr', 'en'];

    private const UNITS = [
        'fr' => ['kg' => 'kg', 't' => 't', 'm3' => 'm³', 'l' => 'l', 'piece' => 'pièce(s)', 'lot' => 'lot(s)', 'package' => 'colis'],
        'en' => ['kg' => 'kg', 't' => 't', 'm3' => 'm³', 'l' => 'l', 'piece' => 'piece(s)', 'lot' => 'lot(s)', 'package' => 'package(s)'],
    ];

    /** @var array<string, array<string, string>> */
    private static array $packagings = [];

    public static function number(mixed $value, string $locale): string
    {
        $text = $locale === 'fr' ? number_format((float) $value, 3, ',', ' ') : number_format((float) $value, 3, '.', ',');

        return str_contains($text, $locale === 'fr' ? ',' : '.') ? rtrim(rtrim($text, '0'), $locale === 'fr' ? ',' : '.') : $text;
    }

    /** "320 kg", "12 Sac": the unit, or the name of the packaging when packages of one packaging are counted. */
    public static function amount(mixed $value, string $unit, ?string $packaging, string $locale): string
    {
        return self::number($value, $locale).' '.self::unit($unit, $packaging, $locale);
    }

    /** "17 m³ sur 20 m³" / "17 m³ of 20 m³"; packages name their packaging once: "50 sur 100 Sac" / "50 of 100 Bag". */
    public static function ratio(mixed $used, mixed $max, string $unit, ?string $packaging, string $locale): string
    {
        $of = $locale === 'fr' ? 'sur' : 'of';

        return $unit === 'package'
            ? self::number($used, $locale)." {$of} ".self::amount($max, $unit, $packaging, $locale)
            : self::amount($used, $unit, null, $locale)." {$of} ".self::amount($max, $unit, null, $locale);
    }

    public static function unit(string $unit, ?string $packaging, string $locale): string
    {
        return $unit === 'package' && $packaging !== null && $packaging !== '' ? self::packaging($packaging, $locale) : (self::UNITS[$locale][$unit] ?? self::UNITS['fr'][$unit] ?? $unit);
    }

    public static function packaging(string $code, string $locale): string
    {
        if (! isset(self::$packagings[$code])) {
            $name = json_decode((string) DB::table('packaging_types')->where('code', $code)->value('name'), true);
            self::$packagings[$code] = is_array($name) ? $name : [];
        }

        return (string) (self::$packagings[$code][$locale] ?? self::$packagings[$code]['fr'] ?? $code);
    }

    /**
     * One parameter of a message in every language: `detail` holds the French text, as before, and `detail_en` the
     * English one. Whoever renders the message takes the one of its reader.
     *
     * @param  callable(string): (string|null)  $text  the text for a locale
     * @return array<string, string|null>
     */
    public static function localized(string $param, callable $text): array
    {
        $params = [];
        foreach (self::LOCALES as $locale) {
            $params[$locale === 'fr' ? $param : "{$param}_{$locale}"] = $text($locale);
        }

        return $params;
    }

    /**
     * The parameters as a reader of this language gets them: `x_en` replaces `x` for an English reader.
     *
     * @param  array<string, scalar|null>  $params
     * @return array<string, scalar|null>
     */
    public static function forLocale(array $params, string $locale): array
    {
        foreach ($params as $key => $value) {
            if (str_ends_with($key, "_{$locale}") && array_key_exists(substr($key, 0, -strlen($locale) - 1), $params)) {
                $params[substr($key, 0, -strlen($locale) - 1)] = $value;
            }
        }

        return $params;
    }
}
