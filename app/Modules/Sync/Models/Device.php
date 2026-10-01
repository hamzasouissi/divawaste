<?php

namespace App\Modules\Sync\Models;

use App\Modules\Common\Models\Concerns\HasPublicUlid;
use App\Modules\Tenancy\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Registered workshop device (PWA install). Shared tablets: each operation records its own user.
 */
class Device extends Model
{
    use BelongsToCompany;
    use HasPublicUlid;

    protected $attributes = ['last_applied_sequence' => 0];

    protected $fillable = ['ulid', 'registered_by_user_id', 'name', 'platform', 'user_agent', 'app_version', 'last_seen_at'];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'last_sync_at' => 'datetime',
            'last_applied_sequence' => 'integer',
            'revoked_at' => 'datetime',
        ];
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }
}
