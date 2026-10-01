<?php

namespace App\Modules\Identity\Models;

use App\Modules\Common\Models\Concerns\HasPublicUlid;
use App\Modules\Identity\Enums\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * Global identity. Tenant roles come from user_role_assignments; HasRoles only carries platform staff roles.
 */
#[UseFactory(UserFactory::class)]
class User extends Authenticatable
{
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasPublicUlid;
    use HasRoles;
    use Notifiable;

    /**
     * Mirrors the column defaults so new instances are complete under strict mode.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'locale' => 'fr',
        'status' => 'active',
        'failed_login_attempts' => 0,
    ];

    protected $fillable = [
        'ulid', 'first_name', 'last_name', 'email', 'phone', 'password', 'locale', 'timezone',
    ];

    protected $hidden = [
        'id', 'password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'password_changed_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted',
            'two_factor_confirmed_at' => 'datetime',
            'failed_login_attempts' => 'integer',
            'locked_until' => 'datetime',
            'last_login_at' => 'datetime',
            'status' => UserStatus::class,
            'anonymized_at' => 'datetime',
        ];
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active && ! $this->isLocked();
    }

    public function setEmailAttribute(string $value): void
    {
        $this->attributes['email'] = mb_strtolower(trim($value));
    }
}
