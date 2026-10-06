<?php

namespace App\Modules\Identity\Models;

use App\Modules\Identity\Enums\Audience;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * System role (company_id NULL, edited by the super-admin) or company custom role.
 */
class Role extends SpatieRole
{
    protected function casts(): array
    {
        return [
            'audience' => Audience::class,
            'is_system' => 'boolean',
            'label' => 'array',
            'description' => 'array',
        ];
    }

    public function isSystem(): bool
    {
        return $this->company_id === null;
    }
}
