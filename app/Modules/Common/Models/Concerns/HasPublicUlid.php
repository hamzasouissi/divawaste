<?php

namespace App\Modules\Common\Models\Concerns;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Support\Str;

/**
 * BIGINT primary key + `ulid` public identifier used for routing and API payloads.
 * A ULID supplied by a client (offline creation) is kept as is.
 */
trait HasPublicUlid
{
    use HasUlids;

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    /**
     * Uppercase Crockford ULID (compact QR alphanumeric mode, same as device-generated ids).
     */
    public function newUniqueId(): string
    {
        return (string) Str::ulid();
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }
}
