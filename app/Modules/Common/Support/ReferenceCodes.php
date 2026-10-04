<?php

namespace App\Modules\Common\Support;

use Illuminate\Support\Facades\DB;

/**
 * Codes of the small global vocabularies (zone types, units, packaging…), read once per table.
 * Registered as a scoped service: the cache lives for one request or one job, never longer.
 */
final class ReferenceCodes
{
    /** @var array<string, array<int, string>> */
    private array $codes = [];

    public function code(string $table, ?int $id): ?string
    {
        if ($id === null) {
            return null;
        }

        $this->codes[$table] ??= DB::table($table)->pluck('code', 'id')->all();

        return $this->codes[$table][$id] ?? null;
    }

    public function id(string $table, ?string $code): ?int
    {
        if ($code === null) {
            return null;
        }

        $this->codes[$table] ??= DB::table($table)->pluck('code', 'id')->all();
        $id = array_search($code, $this->codes[$table], true);

        return $id === false ? null : (int) $id;
    }
}
