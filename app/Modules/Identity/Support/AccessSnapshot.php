<?php

namespace App\Modules\Identity\Support;

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * What the signed-in user may do in the current company, per site. The client uses it to build
 * navigation and hide actions; every endpoint still enforces the same permissions itself.
 */
final class AccessSnapshot
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * @return array{roles: list<array<string, mixed>>, company_permissions: list<string>, sites: list<array<string, mixed>>}
     */
    public function for(User $user): array
    {
        $companyId = $this->context->companyId();

        $rows = DB::table('user_role_assignments as ura')
            ->join('roles as r', 'r.id', '=', 'ura.role_id')
            ->leftJoin('role_has_permissions as rhp', 'rhp.role_id', '=', 'ura.role_id')
            ->leftJoin('permissions as p', 'p.id', '=', 'rhp.permission_id')
            ->where('ura.company_id', $companyId)
            ->where('ura.user_id', $user->id)
            ->get(['ura.site_id', 'r.name as role', 'r.label as role_label', 'p.name as permission']);

        $sites = DB::table('sites')->where('company_id', $companyId)->whereNull('deleted_at')
            ->orderBy('code')->get(['id', 'ulid', 'code', 'name', 'is_active']);

        $everywhere = $rows->whereNull('site_id')->pluck('permission')->filter()->unique()->sort()->values();
        $roles = $rows->unique(fn ($row) => $row->role.'|'.$row->site_id)->map(fn ($row) => [
            'name' => $row->role,
            'label' => json_decode((string) $row->role_label, true),
            'site' => $row->site_id === null ? null : $sites->firstWhere('id', $row->site_id)?->ulid,
        ])->values();

        return [
            'roles' => $roles->all(),
            'company_permissions' => $everywhere->all(),
            'sites' => $sites->map(fn ($site) => [
                'ulid' => $site->ulid,
                'code' => $site->code,
                'name' => $site->name,
                'is_active' => (bool) $site->is_active,
                'permissions' => $rows->where('site_id', $site->id)->pluck('permission')->filter()
                    ->merge($everywhere)->unique()->sort()->values()->all(),
            ])->filter(fn (array $site) => $site['permissions'] !== [])->values()->all(),
        ];
    }
}
