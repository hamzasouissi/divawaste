<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Common\Exceptions\BusinessRuleViolation;
use App\Modules\Identity\Authorization\AccessResolver;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\UserRoleAssignment;
use App\Modules\Identity\Support\CompanyRoles;
use App\Modules\Tenancy\Enums\MembershipStatus;
use App\Modules\Tenancy\Models\CompanyUser;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Members of the current company: role assignments per site, suspension, removal.
 * A company can never be left without an active member able to manage users.
 */
final class ManageMembers
{
    private const ADMIN_PERMISSION = 'users.manage';

    public function __construct(
        private readonly TenantContext $context,
        private readonly CompanyRoles $roles,
        private readonly AccessResolver $access,
    ) {}

    /**
     * Member of the current company by user ULID (a user of another company is simply not found).
     */
    public function member(string $userUlid): CompanyUser
    {
        return CompanyUser::query()->with('user')
            ->whereHas('user', fn ($query) => $query->where('ulid', strtoupper($userUlid)))
            ->where('status', '!=', MembershipStatus::Removed)->firstOrFail();
    }

    /**
     * Replaces every assignment of the member.
     *
     * @param  list<array{role: string, site?: ?string}>  $assignments  site null = all sites
     */
    public function setRoles(CompanyUser $member, array $assignments, User $actor): void
    {
        DB::transaction(function () use ($member, $assignments, $actor) {
            $sites = DB::table('sites')->where('company_id', $this->context->companyId())->whereNull('deleted_at')->pluck('id', 'ulid');
            $rows = [];
            foreach ($assignments as $index => $assignment) {
                $siteUlid = isset($assignment['site']) ? strtoupper($assignment['site']) : null;
                if ($siteUlid !== null && ! $sites->has($siteUlid)) {
                    throw ValidationException::withMessages(["assignments.{$index}.site" => [__('validation.custom.tenant.missing')]]);
                }
                $role = $this->roles->find($assignment['role']);
                $rows[$role->id.'|'.($siteUlid ?? '*')] = ['role_id' => $role->id, 'site_id' => $siteUlid === null ? null : $sites[$siteUlid]];
            }

            // Models (not a bulk delete) so each change is audited and the permission cache is flushed.
            UserRoleAssignment::query()->where('user_id', $member->user_id)->get()->each->delete();
            foreach ($rows as $row) {
                UserRoleAssignment::query()->create(['user_id' => $member->user_id, 'granted_by_user_id' => $actor->id] + $row);
            }

            $this->assertAnAdministratorRemains();
        });
    }

    public function setStatus(CompanyUser $member, MembershipStatus $status): void
    {
        DB::transaction(function () use ($member, $status) {
            $member->forceFill([
                'status' => $status,
                'suspended_at' => $status === MembershipStatus::Suspended ? now() : null,
                'removed_at' => $status === MembershipStatus::Removed ? now() : null,
            ])->save();

            if ($status !== MembershipStatus::Active) {
                // Sessions bound to this company stop immediately (the middleware would refuse them anyway).
                DB::table('personal_access_tokens')->where('tokenable_type', 'user')->where('tokenable_id', $member->user_id)
                    ->where('company_id', $this->context->companyId())->delete();
            }
            if ($status === MembershipStatus::Removed) {
                UserRoleAssignment::query()->where('user_id', $member->user_id)->get()->each->delete();
            }

            $this->access->flush($this->context->companyId());
            $this->assertAnAdministratorRemains();
        });
    }

    private function assertAnAdministratorRemains(): void
    {
        $remains = DB::table('user_role_assignments as ura')
            ->join('company_users as cu', fn ($join) => $join->on('cu.company_id', '=', 'ura.company_id')->on('cu.user_id', '=', 'ura.user_id'))
            ->join('role_has_permissions as rhp', 'rhp.role_id', '=', 'ura.role_id')
            ->join('permissions as p', 'p.id', '=', 'rhp.permission_id')
            ->where('ura.company_id', $this->context->companyId())->whereNull('ura.site_id')
            ->where('cu.status', 'active')->where('p.name', self::ADMIN_PERMISSION)->exists();

        if (! $remains) {
            throw new BusinessRuleViolation('LAST_ADMINISTRATOR', 'The company must keep at least one active administrator.');
        }
    }
}
