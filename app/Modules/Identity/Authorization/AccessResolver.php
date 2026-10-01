<?php

namespace App\Modules\Identity\Authorization;

use App\Modules\Identity\Enums\PermissionScope;
use App\Modules\Identity\Models\User;
use App\Modules\Sites\Models\Site;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Site-aware tenant permissions from user_role_assignments (cahier §2).
 * - site permission: granted on that site by a site assignment or a company-wide one (site_id NULL);
 * - company permission: granted only by a company-wide assignment.
 * Platform permissions stay with Spatie (model_has_roles).
 */
final class AccessResolver
{
    private const ALL_SITES = 0;

    public function __construct(private readonly TenantContext $context) {}

    public function can(User $user, string $permission, Site|int|null $site = null): bool
    {
        $scope = $this->scopeOf($permission);
        if ($scope === null || $scope === PermissionScope::Platform || ! $this->context->hasTenant()) {
            return false;
        }

        $map = $this->permissionMap($user);
        if (in_array($permission, $map[self::ALL_SITES] ?? [], true)) {
            return true;
        }

        if ($scope === PermissionScope::Company) {
            return false;
        }

        $siteId = $site instanceof Site ? $site->id : $site;
        if ($siteId === null) {
            // No site given: true if granted on at least one site (use siteIdsFor() to filter).
            return $this->siteIdsFor($user, $permission) !== [];
        }

        return in_array($permission, $map[$siteId] ?? [], true);
    }

    /**
     * Sites where the permission is granted: null = all sites of the company.
     *
     * @return list<int>|null
     */
    public function siteIdsFor(User $user, string $permission): ?array
    {
        $map = $this->permissionMap($user);
        if (in_array($permission, $map[self::ALL_SITES] ?? [], true)) {
            return null;
        }

        $ids = [];
        foreach ($map as $siteId => $permissions) {
            if ($siteId !== self::ALL_SITES && in_array($permission, $permissions, true)) {
                $ids[] = $siteId;
            }
        }

        return $ids;
    }

    public function isTenantPermission(string $permission): bool
    {
        $scope = $this->scopeOf($permission);

        return $scope !== null && $scope !== PermissionScope::Platform;
    }

    /**
     * Invalidates every cached permission map of a company (assignment or matrix change).
     */
    public function flush(int $companyId): void
    {
        Cache::forever($this->versionKey($companyId), (int) Cache::get($this->versionKey($companyId), 0) + 1);
    }

    /**
     * @return array<int, list<string>> site id (0 = all sites) => permission names
     */
    private function permissionMap(User $user): array
    {
        $companyId = $this->context->companyId();
        $version = (int) Cache::get($this->versionKey($companyId), 0);

        return Cache::remember(
            "t:{$companyId}:u:{$user->id}:perm:v{$version}",
            now()->addMinutes(10),
            function () use ($companyId, $user) {
                $rows = DB::table('user_role_assignments as ura')
                    ->join('company_users as cu', function ($join) {
                        $join->on('cu.company_id', '=', 'ura.company_id')->on('cu.user_id', '=', 'ura.user_id');
                    })
                    ->join('role_has_permissions as rhp', 'rhp.role_id', '=', 'ura.role_id')
                    ->join('permissions as p', 'p.id', '=', 'rhp.permission_id')
                    ->where('ura.company_id', $companyId)
                    ->where('ura.user_id', $user->id)
                    ->where('cu.status', 'active')
                    ->distinct()
                    ->get(['ura.site_scope_key', 'p.name']);

                $map = [];
                foreach ($rows as $row) {
                    $map[(int) $row->site_scope_key][] = (string) $row->name;
                }

                return $map;
            },
        );
    }

    private function scopeOf(string $permission): ?PermissionScope
    {
        $definition = PermissionCatalog::permissions()[$permission] ?? null;

        return $definition[0] ?? null;
    }

    private function versionKey(int $companyId): string
    {
        return "t:{$companyId}:perm-version";
    }
}
