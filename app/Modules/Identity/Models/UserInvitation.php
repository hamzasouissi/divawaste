<?php

namespace App\Modules\Identity\Models;

use App\Modules\Common\Models\Concerns\HasPublicUlid;
use App\Modules\Tenancy\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Email invitation with a role and sites, valid 7 days (M0-08). Accept/send actions come with the auth batch.
 */
class UserInvitation extends Model
{
    use BelongsToCompany;
    use HasPublicUlid;

    public const VALIDITY_DAYS = 7;

    protected $attributes = [
        'status' => 'pending',
        'pending_flag' => true,
        'send_count' => 1,
    ];

    protected $fillable = [
        'ulid', 'email', 'first_name', 'last_name', 'role_id', 'site_ids', 'token_hash', 'message',
        'invited_by_user_id', 'expires_at', 'last_sent_at',
    ];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'site_ids' => 'array',
            'pending_flag' => 'boolean',
            'expires_at' => 'datetime',
            'last_sent_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
