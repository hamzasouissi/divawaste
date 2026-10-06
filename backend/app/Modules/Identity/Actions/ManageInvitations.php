<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Common\Exceptions\BusinessRuleViolation;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\UserInvitation;
use App\Modules\Identity\Models\UserRoleAssignment;
use App\Modules\Identity\Notifications\InvitationNotification;
use App\Modules\Identity\Support\CompanyRoles;
use App\Modules\Identity\Support\LegalAcceptance;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Models\CompanyUser;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * M0-08: invitation by email with a role and one or several sites; the link expires after 7 days.
 * Only the SHA-256 of the token is stored: the link cannot be rebuilt from the database.
 */
final class ManageInvitations
{
    public function __construct(private readonly TenantContext $context, private readonly CompanyRoles $roles) {}

    /**
     * @param  array{email: string, first_name?: ?string, last_name?: ?string, role: string, sites?: list<string>, message?: ?string}  $input
     */
    public function invite(array $input, User $inviter): UserInvitation
    {
        $email = mb_strtolower(trim($input['email']));
        $role = $this->roles->find($input['role']);
        $sites = $this->siteUlids($input['sites'] ?? []);

        $alreadyMember = DB::table('company_users as m')->join('users as u', 'u.id', '=', 'm.user_id')
            ->where('m.company_id', $this->context->companyId())->where('u.email', $email)->where('m.status', '!=', 'removed')->exists();
        if ($alreadyMember) {
            throw new BusinessRuleViolation('ALREADY_MEMBER', 'This person is already a member of the company.');
        }
        if (UserInvitation::query()->where('email', $email)->where('pending_flag', true)->exists()) {
            throw new BusinessRuleViolation('INVITATION_PENDING', 'An invitation is already pending for this address.');
        }

        $token = Str::random(64);
        $invitation = UserInvitation::query()->create([
            'email' => $email,
            'first_name' => $input['first_name'] ?? null,
            'last_name' => $input['last_name'] ?? null,
            'role_id' => $role->id,
            'site_ids' => $sites,
            'token_hash' => hash('sha256', $token),
            'message' => $input['message'] ?? null,
            'invited_by_user_id' => $inviter->id,
            'expires_at' => now()->addDays(UserInvitation::VALIDITY_DAYS),
            'last_sent_at' => now(),
        ]);

        $this->send($invitation, $token, $inviter, $role);

        return $invitation;
    }

    public function resend(UserInvitation $invitation, User $inviter): UserInvitation
    {
        $this->assertPending($invitation, allowExpired: true);

        // A new link each time: the previous one stops working.
        $token = Str::random(64);
        $invitation->forceFill([
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays(UserInvitation::VALIDITY_DAYS),
            'last_sent_at' => now(),
            'send_count' => $invitation->send_count + 1,
        ])->save();

        $this->send($invitation, $token, $inviter, Role::query()->findOrFail($invitation->role_id));

        return $invitation;
    }

    public function revoke(UserInvitation $invitation, User $actor): void
    {
        $this->assertPending($invitation, allowExpired: true);
        $invitation->forceFill(['status' => 'revoked', 'pending_flag' => null, 'revoked_at' => now(), 'revoked_by_user_id' => $actor->id])->save();
    }

    /**
     * Public lookup by token (no tenant context yet).
     */
    public function findByToken(string $token): UserInvitation
    {
        $invitation = $this->context->runAsSystem('invitation lookup by token', fn () => UserInvitation::query()
            ->where('token_hash', hash('sha256', $token))->first());

        if ($invitation === null || $invitation->status !== 'pending' || $invitation->isExpired()) {
            throw ValidationException::withMessages(['token' => [__('validation.custom.invitation.invalid')]]);
        }

        return $invitation;
    }

    /**
     * @param  array{first_name?: ?string, last_name?: ?string, password?: ?string}  $input
     */
    public function accept(UserInvitation $invitation, array $input, Request $request): User
    {
        $company = Company::query()->findOrFail($invitation->company_id);

        return DB::transaction(fn () => $this->context->runAs($company, function () use ($invitation, $input, $request, $company) {
            $invitation = UserInvitation::query()->whereKey($invitation->id)->lockForUpdate()->firstOrFail();
            $this->assertPending($invitation, allowExpired: false);

            $user = User::query()->where('email', $invitation->email)->first();
            if ($user === null) {
                $user = User::query()->create([
                    'first_name' => $input['first_name'] ?? $invitation->first_name,
                    'last_name' => $input['last_name'] ?? $invitation->last_name,
                    'email' => $invitation->email,
                    'password' => $input['password'],
                    'locale' => $company->default_locale,
                ]);
            }
            // The link was delivered to this mailbox: the address is confirmed.
            if ($user->email_verified_at === null) {
                $user->forceFill(['email_verified_at' => now()])->save();
            }

            CompanyUser::query()->updateOrCreate(['user_id' => $user->id], [
                'status' => 'active', 'invited_by_user_id' => $invitation->invited_by_user_id, 'joined_at' => now(),
            ]);

            $siteIds = $invitation->site_ids === [] || $invitation->site_ids === null
                ? [null]
                : DB::table('sites')->where('company_id', $company->id)->whereIn('ulid', $invitation->site_ids)->whereNull('deleted_at')->pluck('id')->all();
            foreach ($siteIds as $siteId) {
                UserRoleAssignment::query()->firstOrCreate(
                    ['user_id' => $user->id, 'role_id' => $invitation->role_id, 'site_id' => $siteId],
                    ['granted_by_user_id' => $invitation->invited_by_user_id],
                );
            }

            $invitation->forceFill(['status' => 'accepted', 'pending_flag' => null, 'accepted_at' => now(), 'accepted_user_id' => $user->id])->save();
            LegalAcceptance::record($user, $company->id, ['terms', 'privacy'], 'invitation', $request);

            return $user;
        }));
    }

    private function assertPending(UserInvitation $invitation, bool $allowExpired): void
    {
        if ($invitation->status !== 'pending' || (! $allowExpired && $invitation->isExpired())) {
            throw new BusinessRuleViolation('INVITATION_NOT_PENDING', 'This invitation is no longer pending.');
        }
    }

    /**
     * @param  list<string>  $ulids
     * @return list<string> validated site ULIDs of the current company (empty = all sites)
     */
    private function siteUlids(array $ulids): array
    {
        $ulids = array_values(array_unique(array_map('strtoupper', $ulids)));
        $known = DB::table('sites')->where('company_id', $this->context->companyId())->whereIn('ulid', $ulids)->whereNull('deleted_at')->pluck('ulid')->all();

        if (count($known) !== count($ulids)) {
            throw ValidationException::withMessages(['sites' => [__('validation.custom.tenant.missing')]]);
        }

        return $known;
    }

    private function send(UserInvitation $invitation, string $token, User $inviter, Role $role): void
    {
        $company = Company::query()->findOrFail($this->context->companyId());
        $locale = $company->default_locale;

        Notification::route('mail', $invitation->email)->notify((new InvitationNotification(
            $token, $company->legal_name, $inviter->fullName(), (string) ($role->label[$locale] ?? $role->name), $invitation->message,
        ))->locale($locale));
    }
}
