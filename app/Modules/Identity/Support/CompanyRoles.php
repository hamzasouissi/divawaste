<?php

namespace App\Modules\Identity\Support;

use App\Modules\Identity\Authorization\PermissionCatalog;
use App\Modules\Identity\Enums\Audience;
use App\Modules\Identity\Enums\PermissionScope;
use App\Modules\Identity\Models\Role;
use App\Modules\Tenancy\Enums\CompanyType;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Roles a company can hand out: the system roles of its audience plus the roles it created itself (cahier §2).
 */
final class CompanyRoles
{
    public function __construct(private readonly TenantContext $context) {}

    public function audience(): Audience
    {
        return $this->context->companyType() === CompanyType::Provider ? Audience::Provider : Audience::Industrial;
    }

    /**
     * @return Builder<Role>
     */
    public function query(): Builder
    {
        return Role::query()->where('audience', $this->audience())
            ->where(fn (Builder $query) => $query->whereNull('company_id')->orWhere('company_id', $this->context->companyId()));
    }

    public function find(string $name): Role
    {
        // A company role shadows a system role of the same name.
        $role = $this->query()->where('name', $name)->orderByRaw('company_id is null')->first();

        if ($role === null) {
            throw ValidationException::withMessages(['role' => [__('validation.exists')]]);
        }

        return $role;
    }

    /**
     * Permissions a company of this audience may put in its own roles (never platform ones).
     *
     * @return list<string>
     */
    public function grantablePermissions(): array
    {
        $audience = $this->audience();

        return array_keys(array_filter(
            PermissionCatalog::permissions(),
            fn (array $definition) => $definition[0] !== PermissionScope::Platform && in_array($definition[1], [Audience::Any, $audience], true),
        ));
    }
}
