<?php

namespace App\Modules\Catalog\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Loads one file of a colour library into the reference catalogue. The file is a JSON list of records holding a
 * reference (`pantone`) and a colour (`hex`, or `r`/`g`/`b`). Nothing is guessed: a record without a reference or
 * without a readable colour is refused and reported. Running the same file again changes nothing.
 */
final class ColorCatalogueImporter
{
    /** File name (without extension) → collection. A file named otherwise needs the collection said explicitly. */
    public const COLLECTIONS = [
        'pantone-coated' => 'coated',
        'pantone-uncoated' => 'uncoated',
        'pantone-metallic' => 'metallic',
        'pantone-pastels-neons' => 'pastels_neons',
        'pantone-skin' => 'skin',
        'pantone-color-of-the-year' => 'color_of_the_year',
    ];

    private const ISSUES_KEPT = 50;

    /**
     * @return array{file: string, collection: string, records: int, inserted: int, updated: int, unchanged: int, duplicates: int, invalid: int, warnings: int, issues: list<string>}
     */
    public function import(string $path, ?string $collection = null, bool $dryRun = false, ?string $sourceName = null): array
    {
        $file = basename($path);
        $collection ??= self::COLLECTIONS[pathinfo($file, PATHINFO_FILENAME)] ?? null;
        if ($collection === null || preg_match('/^[a-z][a-z0-9_]{0,29}$/', $collection) !== 1) {
            throw new InvalidArgumentException("{$file}: unknown collection. Name it with --collection (lowercase letters, digits and underscores).");
        }
        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException("{$file}: the file cannot be read.");
        }
        $records = json_decode((string) file_get_contents($path), true);
        if (! is_array($records) || ! array_is_list($records)) {
            throw new InvalidArgumentException("{$file}: not a JSON list of colours".(json_last_error() === JSON_ERROR_NONE ? '.' : ' ('.json_last_error_msg().').'));
        }

        $summary = ['file' => $file, 'collection' => $collection, 'records' => count($records), 'inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'duplicates' => 0, 'invalid' => 0, 'warnings' => 0, 'issues' => []];
        $issue = function (string $kind, string $message) use (&$summary): void {
            $summary[$kind]++;
            if (count($summary['issues']) < self::ISSUES_KEPT) {
                $summary['issues'][] = $message;
            }
        };

        $sourceName = Str::limit($sourceName ?? $file, 120, '');
        $existing = Colors::catalogue()->where('color_system', 'pantone')->where('collection', $collection)->get(['id', 'reference_code', 'hex_color', 'source_name', 'is_active'])->keyBy('reference_code');
        $seen = [];
        $now = now();

        $write = function () use ($records, $collection, $sourceName, $existing, $now, $dryRun, $issue, &$summary, &$seen): void {
            foreach ($records as $index => $record) {
                $line = '#'.($index + 1);
                $reference = is_array($record) && is_string($record['pantone'] ?? null) ? trim($record['pantone']) : '';
                if ($reference === '' || mb_strlen($reference) > 60) {
                    $issue('invalid', "{$line}: no usable reference.");

                    continue;
                }

                $hex = ColorMath::normalizeHex($record['hex'] ?? null);
                $channels = array_map(fn (string $key) => ColorMath::channel($record[$key] ?? null), ['r', 'g', 'b']);
                $hasRgb = ! in_array(null, $channels, true);
                if ($hex === null && array_key_exists('hex', $record) && $record['hex'] !== null && $record['hex'] !== '') {
                    $issue('invalid', "{$line} {$reference}: \"".Str::limit(is_scalar($record['hex']) ? (string) $record['hex'] : gettype($record['hex']), 20).'" is not a HEX colour.');

                    continue;
                }
                if ($hex === null && ! $hasRgb) {
                    $issue('invalid', "{$line} {$reference}: no colour value.");

                    continue;
                }
                if ($hex === null) {
                    $hex = ColorMath::hex(...$channels);
                } elseif ($hasRgb && ColorMath::hex(...$channels) !== $hex) {
                    // The file contradicts itself: the HEX value is the one every file carries, so it is kept.
                    $issue('warnings', "{$line} {$reference}: r/g/b (".implode(', ', $channels).") do not give {$hex}; HEX kept.");
                }

                if (isset($seen[$reference])) {
                    $issue('duplicates', $seen[$reference] === $hex ? "{$line} {$reference}: listed twice." : "{$line} {$reference}: listed twice with another colour ({$hex}); the first ({$seen[$reference]}) is kept.");

                    continue;
                }
                $seen[$reference] = $hex;

                $row = $existing->get($reference);
                if ($row !== null && $row->hex_color === $hex && $row->source_name === $sourceName && (bool) $row->is_active) {
                    $summary['unchanged']++;

                    continue;
                }
                $values = Colors::derived($hex) + ['source_name' => $sourceName, 'is_active' => true, 'updated_at' => $now];
                if ($row !== null) {
                    $summary['updated']++;
                    $dryRun || DB::table('color_families')->where('id', $row->id)->update($values);

                    continue;
                }
                $summary['inserted']++;
                $dryRun || DB::table('color_families')->insert($values + [
                    'company_id' => null, 'code' => 'pc_'.Str::lower((string) Str::ulid()), 'color_system' => 'pantone', 'collection' => $collection, 'reference_code' => $reference,
                    // The library gives no name beside the reference: the reference is the name.
                    'name' => json_encode(['fr' => $reference, 'en' => $reference], JSON_UNESCAPED_UNICODE), 'pantone_reference' => Str::limit($reference, 40, ''),
                    'source' => Colors::SOURCE_IMPORT, 'verification_status' => 'unverified', 'sort_order' => 0, 'created_at' => $now,
                ]);
            }
        };
        $dryRun ? $write() : DB::transaction($write);

        return $summary;
    }
}
