<?php

namespace App\Modules\Identity\Models;

use App\Modules\Identity\Authorization\AccessResolver;
use App\Modules\Tenancy\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * User × role × site (site_id NULL = all sites of the company).
 */
class UserRoleAssignment extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'user_id', 'role_id', 'site_id', 'granted_by_user_id'];

    protected static function booted(): void
    {
        $flush = fn (self $assignment) => app(AccessResolver::class)->flush((int) $assignment->company_id);
        static::saved($flush);
        static::deleted($flush);
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
