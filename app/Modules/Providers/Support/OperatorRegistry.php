<?php

namespace App\Modules\Providers\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The official lists of waste operators (ANGed): loaded from the file `scripts/anged/extract.py` writes, searched by
 * every company. An entry is the agency's word, not the platform's: the platform does not check it, and an operator
 * listed there has no account until it registers.
 */
final class OperatorRegistry
{
    public const REGISTRY_ANGED = 'anged';

    private const FIELDS = [
        'list_code', 'list_title', 'hazardous', 'legal_name', 'contact_name', 'phone', 'fax', 'email', 'head_office',
        'plant_address', 'governorate', 'activity', 'waste', 'is_collector', 'is_transporter', 'is_recycler', 'is_sorter',
        'is_storer', 'authorization_number', 'valid_from', 'valid_until', 'annual_tonnage', 'collection_area', 'withdrawal',
        'source_file', 'source_url',
    ];

    /** Column length limits (the lists sometimes print long cells). */
    private const LIMITS = [
        'list_code' => 40, 'list_title' => 191, 'legal_name' => 191, 'contact_name' => 191, 'phone' => 100, 'fax' => 100,
        'email' => 191, 'head_office' => 255, 'plant_address' => 255, 'governorate' => 40, 'activity' => 255,
        'authorization_number' => 60, 'annual_tonnage' => 255, 'withdrawal' => 255, 'source_file' => 191, 'source_url' => 255,
    ];

    /**
     * Replaces the registry's entries by the file's: new ones are added, changed ones updated, those the file no
     * longer lists removed. Running the same file again changes nothing.
     *
     * @return array{registry: string, entries: int, inserted: int, updated: int, unchanged: int, removed: int, invalid: int}
     */
    public function import(string $path, bool $dryRun = false): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException(basename($path).': the file cannot be read.');
        }
        $payload = json_decode((string) file_get_contents($path), true);
        if (! is_array($payload) || ! is_array($payload['operators'] ?? null)) {
            throw new InvalidArgumentException(basename($path).': not a registry file (an object with "operators").');
        }
        $registry = (string) ($payload['registry'] ?? self::REGISTRY_ANGED);
        $extractedOn = is_string($payload['extracted_on'] ?? null) ? $payload['extracted_on'] : null;

        $summary = ['registry' => $registry, 'entries' => count($payload['operators']), 'inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'removed' => 0, 'invalid' => 0];
        $existing = DB::table('regulatory_operators')->where('registry', $registry)->get()->keyBy('fingerprint');
        $seen = [];
        $now = now();

        $write = function () use ($payload, $registry, $extractedOn, $existing, $dryRun, $now, &$summary, &$seen): void {
            $inserts = [];
            foreach ($payload['operators'] as $entry) {
                $fingerprint = is_array($entry) ? (string) ($entry['fingerprint'] ?? '') : '';
                if (preg_match('/^[0-9a-f]{40}$/', $fingerprint) !== 1 || trim((string) ($entry['legal_name'] ?? '')) === '' || isset($seen[$fingerprint])) {
                    $summary['invalid']++;

                    continue;
                }
                $seen[$fingerprint] = true;
                $row = $this->row($entry) + ['extracted_on' => $extractedOn];
                $current = $existing->get($fingerprint);
                if ($current === null) {
                    $summary['inserted']++;
                    $inserts[] = $row + ['ulid' => (string) Str::ulid(), 'registry' => $registry, 'fingerprint' => $fingerprint, 'created_at' => $now, 'updated_at' => $now];
                } elseif ($this->differs($current, $row)) {
                    $summary['updated']++;
                    $dryRun || DB::table('regulatory_operators')->where('id', $current->id)->update($row + ['updated_at' => $now]);
                } else {
                    $summary['unchanged']++;
                }
            }
            if (! $dryRun) {
                foreach (array_chunk($inserts, 500) as $chunk) {
                    DB::table('regulatory_operators')->insert($chunk);
                }
            }
            $gone = $existing->keys()->diff(array_keys($seen));
            $summary['removed'] = $gone->count();
            if (! $dryRun && $gone->isNotEmpty()) {
                foreach ($gone->chunk(500) as $chunk) {
                    DB::table('regulatory_operators')->where('registry', $registry)->whereIn('fingerprint', $chunk->all())->delete();
                }
            }
        };
        $dryRun ? $write() : DB::transaction($write);

        return $summary;
    }

    /**
     * Entries matching what the user looks for: words of the name, contact, address or waste; a list; an activity; a
     * governorate. Hazardous lists first, then by name.
     *
     * @param  array{q?: string|null, list?: string|null, role?: string|null, governorate?: string|null, hazardous?: bool|null, valid?: bool}  $filters
     */
    public static function search(array $filters): Builder
    {
        $query = DB::table('regulatory_operators');
        $words = array_values(array_filter(preg_split('/\s+/', trim((string) ($filters['q'] ?? ''))) ?: [], fn (string $word) => mb_strlen($word) >= 2));
        $pattern = fn (string $word) => '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($word)).'%';
        foreach ($words as $word) {
            $like = $pattern($word);
            $query->where(fn (Builder $q) => $q->whereRaw('LOWER(legal_name) like ?', [$like])->orWhereRaw('LOWER(contact_name) like ?', [$like])
                ->orWhereRaw('LOWER(waste) like ?', [$like])->orWhereRaw('LOWER(list_title) like ?', [$like])
                ->orWhereRaw('LOWER(head_office) like ?', [$like])->orWhereRaw('LOWER(plant_address) like ?', [$like])
                ->orWhereRaw('LOWER(authorization_number) like ?', [$like])->orWhere('phone', 'like', $like));
        }
        $query->when($filters['list'] ?? null, fn (Builder $q, string $list) => $q->where('list_code', $list))
            ->when($filters['governorate'] ?? null, fn (Builder $q, string $governorate) => $q->where('governorate', $governorate))
            ->when(isset($filters['hazardous']), fn (Builder $q) => $q->where('hazardous', (bool) $filters['hazardous']))
            ->when(in_array($filters['role'] ?? null, ['collector', 'transporter', 'recycler', 'sorter', 'storer'], true), fn (Builder $q) => $q->where('is_'.$filters['role'], true))
            // Valid: an authorisation whose end date has not passed, or an entry of a list without dates.
            ->when($filters['valid'] ?? false, fn (Builder $q) => $q->where(fn (Builder $v) => $v->whereNull('valid_until')->orWhere('valid_until', '>=', now()->toDateString())));

        // What the user typed found in the name first, then in the list's title (« textile » → the textile list), then elsewhere.
        if ($words !== []) {
            $first = $pattern($words[0]);
            $query->orderByRaw('CASE WHEN LOWER(legal_name) LIKE ? THEN 0 WHEN LOWER(list_title) LIKE ? THEN 1 ELSE 2 END', [$first, $first]);
        }

        return $query->orderByDesc('hazardous')->orderBy('legal_name')->orderBy('id');
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function row(array $entry): array
    {
        $entry['list_code'] ??= $entry['list'] ?? null;  // the extractor writes "list"
        $row = [];
        foreach (self::FIELDS as $field) {
            $value = $entry[$field] ?? null;
            if (str_starts_with($field, 'is_') || $field === 'hazardous') {
                $row[$field] = (bool) $value;
            } elseif (in_array($field, ['valid_from', 'valid_until'], true)) {
                $row[$field] = is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
            } else {
                $text = is_scalar($value) ? trim((string) $value) : '';
                $row[$field] = $text === '' ? null : (isset(self::LIMITS[$field]) ? Str::limit($text, self::LIMITS[$field], '') : $text);
            }
        }
        $row['list_code'] ??= 'other';
        $row['list_title'] ??= $row['list_code'];

        return $row;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function differs(object $current, array $row): bool
    {
        foreach ($row as $field => $value) {
            $stored = $current->{$field} ?? null;
            if (is_bool($value) ? (bool) $stored !== $value : (string) ($stored ?? '') !== (string) ($value ?? '')) {
                return true;
            }
        }

        return false;
    }
}
