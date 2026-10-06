<?php

namespace App\Modules\Identity\Models;

use App\Modules\Identity\Enums\Audience;
use App\Modules\Identity\Enums\PermissionScope;
use Spatie\Permission\Models\Permission as SpatiePermission;

class Permission extends SpatiePermission
{
    protected function casts(): array
    {
        return [
            'scope_level' => PermissionScope::class,
            'audience' => Audience::class,
            'label' => 'array',
            'description' => 'array',
        ];
    }
}
