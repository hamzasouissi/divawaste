<?php

use App\Modules\Common\Exceptions\BusinessRuleViolation;
use App\Modules\Identity\Actions\AttemptLogin;
use App\Modules\Identity\Actions\ManageInvitations;
use App\Modules\Identity\Actions\ManageMembers;
use App\Modules\Identity\Actions\ManageTwoFactor;
use App\Modules\Identity\Authorization\AccessResolver;
use App\Modules\Identity\Authorization\PermissionCatalog;
use App\Modules\Identity\Enums\Audience;
use App\Modules\Identity\Enums\PermissionScope;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\UserInvitation;
use App\Modules\Identity\Notifications\VerifyEmailNotification;
use App\Modules\Identity\Resources\MeResource;
use App\Modules\Identity\Support\AccessSnapshot;
use App\Modules\Identity\Support\CompanyRoles;
use App\Modules\Identity\Support\EmailVerification;
use App\Modules\Identity\Support\LegalAcceptance;
use App\Modules\Identity\Support\PasswordRules;
use App\Modules\Notifications\Actions\NotifyUsers;
use App\Modules\Tenancy\Enums\MembershipStatus;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Resources\CompanyResource;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/*
 * Public authentication endpoints (strict per-IP limits: they accept credentials or send mail).
 */
Route::post('auth/login', fn (Request $request, AttemptLogin $action) => response()->json(['data' => $action->handle($request->validate([
    'email' => ['required', 'string', 'email'],
    'password' => ['required', 'string'],
    'company' => ['sometimes', 'nullable', 'string', 'ulid'],
    'code' => ['sometimes', 'nullable', 'string', 'max:10'],
    'recovery_code' => ['sometimes', 'nullable', 'string', 'max:50'],
    'mode' => ['sometimes', 'nullable', Rule::in(['token', 'session'])],
]), $request)]))->name('auth.login')->middleware('throttle:5,1');

Route::post('auth/forgot-password', function (Request $request) {
    // Always the same answer: the endpoint must not reveal whether an address has an account.
    Password::sendResetLink($request->validate(['email' => ['required', 'string', 'email']]));

    return response()->noContent();
})->name('auth.forgot-password')->middleware('throttle:5,1');

Route::post('auth/reset-password', function (Request $request) {
    $input = $request->validate([
        'email' => ['required', 'string', 'email'],
        'token' => ['required', 'string'],
        'password' => PasswordRules::rules(),
    ]);

    $status = Password::reset($input, function (User $user, string $password) {
        $user->forceFill([
            'password' => $password,
            'password_changed_at' => now(),
            'failed_login_attempts' => 0,
            'locked_until' => null,
        ])->save();
        // A reset proves control of the mailbox and invalidates every existing session.
        $user->tokens()->delete();
        event(new PasswordReset($user));
    });

    if ($status !== Password::PASSWORD_RESET) {
        throw ValidationException::withMessages(['token' => [__($status)]]);
    }

    return response()->noContent();
})->name('auth.reset-password')->middleware('throttle:5,1');

Route::post('auth/email/verify', function (Request $request) {
    $input = $request->validate([
        'user' => ['required', 'string', 'ulid'],
        'expires' => ['required', 'integer'],
        'signature' => ['required', 'string', 'size:64'],
    ]);
    $user = User::query()->where('ulid', strtoupper($input['user']))->first();

    if ($user === null || ! EmailVerification::isValid($user, (int) $input['expires'], $input['signature'])) {
        throw ValidationException::withMessages(['signature' => [__('validation.custom.email_verification.invalid')]]);
    }

    if ($user->email_verified_at === null) {
        $user->forceFill(['email_verified_at' => now()])->save();
        // M0-06: a registration only reaches the platform review queue once the contact's email is confirmed.
        foreach (Company::query()->where('primary_contact_user_id', $user->id)->where('status', 'pending_verification')->get() as $company) {
            $company->forceFill(['status' => 'pending_approval', 'submitted_at' => now()])->save();
            app(NotifyUsers::class)->toPlatformPermission('platform.companies.review', 'company.pending_review', [
                'company' => $company->legal_name, 'type' => $company->company_type->value,
            ], '/platform/companies/'.$company->ulid);
        }
    }

    return response()->json(['data' => ['email' => $user->email, 'verified' => true]]);
})->name('auth.email.verify')->middleware('throttle:10,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('me', fn (Request $request) => new MeResource($request->user()))->name('me');

    Route::put('me', function (Request $request) {
        $request->user()->update($request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:30'],
            'locale' => ['required', Rule::in(['fr', 'en', 'ar'])],
        ]));

        return new MeResource($request->user()->refresh());
    })->name('me.update');

    Route::put('me/password', function (Request $request) {
        $input = $request->validate(['current_password' => ['required', 'string'], 'password' => PasswordRules::rules()]);
        $user = $request->user();

        if (! Hash::check($input['current_password'], $user->getAuthPassword())) {
            throw ValidationException::withMessages(['current_password' => [__('auth.password')]]);
        }

        $user->forceFill(['password' => $input['password'], 'password_changed_at' => now()])->save();
        // Other devices are signed out; the current one stays.
        $current = $user->currentAccessToken();
        $others = $user->tokens();
        if ($current instanceof PersonalAccessToken) {
            $others->whereKeyNot($current->getKey());
        }
        $others->delete();

        return response()->noContent();
    })->name('me.password');

    Route::post('auth/email/resend', function (Request $request) {
        if ($request->user()->email_verified_at === null) {
            $request->user()->notify(new VerifyEmailNotification);
        }

        return response()->noContent();
    })->name('auth.email.resend')->middleware('throttle:3,1');

    Route::post('auth/logout', function (Request $request) {
        $token = $request->user()->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        } elseif ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->noContent();
    })->name('auth.logout');

    Route::post('auth/switch-company', function (Request $request, AttemptLogin $login) {
        $ulid = strtoupper($request->validate(['company' => ['required', 'string', 'ulid']])['company']);
        $user = $request->user();
        $company = $login->activeCompanies($user)->firstWhere('ulid', $ulid);
        abort_if($company === null, 403);

        $token = $user->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            $token->forceFill(['company_id' => $company->id])->save();
        } elseif ($request->hasSession()) {
            $request->session()->put('current_company_id', $company->id);
        }
        $user->forceFill(['last_company_id' => $company->id])->save();

        return response()->json(['data' => ['company' => ['ulid' => $company->ulid, 'legal_name' => $company->legal_name]]]);
    })->name('auth.switch-company');

    // Optional TOTP (M0-09).
    Route::post('me/two-factor', fn (Request $request, ManageTwoFactor $action) => response()->json(['data' => $action->start($request->user())]))
        ->name('me.two-factor.start');

    Route::post('me/two-factor/confirm', fn (Request $request, ManageTwoFactor $action) => response()->json(['data' => [
        'recovery_codes' => $action->confirm($request->user(), (string) $request->validate(['code' => ['required', 'string', 'max:10']])['code']),
    ]]))->name('me.two-factor.confirm');

    Route::delete('me/two-factor', function (Request $request, ManageTwoFactor $action) {
        $password = (string) $request->validate(['password' => ['required', 'string']])['password'];
        if (! Hash::check($password, $request->user()->getAuthPassword())) {
            throw ValidationException::withMessages(['password' => [__('auth.password')]]);
        }
        $action->disable($request->user());

        return response()->noContent();
    })->name('me.two-factor.disable');
});

// Pending and suspended companies can still see who they are and why they are blocked.
Route::middleware(['auth:sanctum', 'tenant:active,pending_verification,pending_approval,suspended'])
    ->get('me/access', function (Request $request, AccessSnapshot $access, TenantContext $context) {
        $company = Company::query()->findOrFail($context->companyId());

        return response()->json(['data' => [
            'company' => (new CompanyResource($company))->resolve($request),
        ] + $access->for($request->user())]);
    })->name('me.access');

/*
 * Invitations (M0-08): public acceptance by token.
 */
Route::get('auth/invitations/{token}', function (string $token, ManageInvitations $invitations) {
    $invitation = $invitations->findByToken($token);

    return response()->json(['data' => [
        'email' => $invitation->email,
        'first_name' => $invitation->first_name,
        'last_name' => $invitation->last_name,
        'company' => DB::table('companies')->where('id', $invitation->company_id)->value('legal_name'),
        'role' => json_decode((string) DB::table('roles')->where('id', $invitation->role_id)->value('label'), true),
        'expires_at' => $invitation->expires_at->toIso8601ZuluString(),
        // An existing account joins with its own password; a new one must choose a password.
        'has_account' => User::query()->where('email', $invitation->email)->exists(),
        'legal_documents' => LegalAcceptance::current(['terms', 'privacy'])
            ->map(fn ($doc) => ['type' => $doc->document_type, 'version' => $doc->version, 'title' => $doc->title, 'content_html' => $doc->content_html]),
    ]]);
})->name('auth.invitations.show')->middleware('throttle:20,1');

Route::post('auth/invitations/{token}/accept', function (Request $request, string $token, ManageInvitations $invitations) {
    $invitation = $invitations->findByToken($token);
    $isNew = ! User::query()->where('email', $invitation->email)->exists();

    $user = $invitations->accept($invitation, $request->validate([
        'first_name' => [$isNew ? 'required' : 'nullable', 'string', 'max:100'],
        'last_name' => [$isNew ? 'required' : 'nullable', 'string', 'max:100'],
        'password' => $isNew ? PasswordRules::rules() : ['nullable'],
        'accept_terms' => ['accepted'],
        'accept_privacy' => ['accepted'],
    ]), $request);

    return response()->json(['data' => ['email' => $user->email]], 201);
})->name('auth.invitations.accept')->middleware('throttle:10,1');

/*
 * Users, invitations and roles of the current company.
 */
Route::middleware(['auth:sanctum', 'tenant'])->group(function () {
    $allow = fn (Request $request, string $permission) => abort_unless($request->user()->can($permission), 403);

    $invitationData = fn (UserInvitation $invitation) => [
        'ulid' => $invitation->ulid,
        'email' => $invitation->email,
        'first_name' => $invitation->first_name,
        'last_name' => $invitation->last_name,
        'role' => DB::table('roles')->where('id', $invitation->role_id)->value('name'),
        'sites' => $invitation->site_ids ?? [],
        'status' => $invitation->status === 'pending' && $invitation->isExpired() ? 'expired' : $invitation->status,
        'expires_at' => $invitation->expires_at->toIso8601ZuluString(),
        'last_sent_at' => $invitation->last_sent_at->toIso8601ZuluString(),
        'send_count' => $invitation->send_count,
    ];

    Route::get('users', function (Request $request, TenantContext $context) use ($allow) {
        $allow($request, 'users.view');
        $companyId = $context->companyId();
        $like = fn (string $value) => '%'.addcslashes($value, '%_\\').'%';

        $members = DB::table('company_users as m')->join('users as u', 'u.id', '=', 'm.user_id')
            ->where('m.company_id', $companyId)->where('m.status', '!=', 'removed')
            ->when($request->string('search')->toString(), fn ($query, $value) => $query->where(fn ($q) => $q
                ->where('u.email', 'like', $like($value))->orWhere('u.first_name', 'like', $like($value))->orWhere('u.last_name', 'like', $like($value))))
            ->orderBy('u.last_name')->orderBy('u.first_name')
            ->select(['u.id', 'u.ulid', 'u.first_name', 'u.last_name', 'u.email', 'u.phone', 'u.last_login_at', 'u.two_factor_confirmed_at',
                'm.status', 'm.job_title', 'm.joined_at'])
            ->paginate(min((int) $request->integer('per_page', 25), 100));

        $assignments = DB::table('user_role_assignments as a')->join('roles as r', 'r.id', '=', 'a.role_id')
            ->leftJoin('sites as s', 's.id', '=', 'a.site_id')
            ->where('a.company_id', $companyId)->whereIn('a.user_id', collect($members->items())->pluck('id'))
            ->get(['a.user_id', 'r.name as role', 'r.label', 's.ulid as site', 's.name as site_name'])->groupBy('user_id');

        return response()->json([
            'data' => collect($members->items())->map(fn ($member) => [
                'ulid' => $member->ulid,
                'first_name' => $member->first_name,
                'last_name' => $member->last_name,
                'email' => $member->email,
                'phone' => $member->phone,
                'job_title' => $member->job_title,
                'status' => $member->status,
                'two_factor_enabled' => $member->two_factor_confirmed_at !== null,
                'joined_at' => $member->joined_at,
                'last_login_at' => $member->last_login_at,
                'assignments' => ($assignments[$member->id] ?? collect())->map(fn ($row) => [
                    'role' => $row->role, 'label' => json_decode((string) $row->label, true), 'site' => $row->site, 'site_name' => $row->site_name,
                ])->values(),
            ]),
            'meta' => ['current_page' => $members->currentPage(), 'last_page' => $members->lastPage(), 'per_page' => $members->perPage(), 'total' => $members->total()],
        ]);
    })->name('users.index');

    Route::put('users/{user}/roles', function (Request $request, string $user, ManageMembers $members) use ($allow) {
        $allow($request, 'users.manage');
        $input = $request->validate([
            'assignments' => ['present', 'array', 'max:200'],
            'assignments.*.role' => ['required', 'string', 'max:125'],
            'assignments.*.site' => ['nullable', 'string', 'ulid'],
        ]);
        $members->setRoles($members->member($user), $input['assignments'], $request->user());

        return response()->noContent();
    })->name('users.roles.update');

    Route::post('users/{user}/suspend', function (Request $request, string $user, ManageMembers $members) use ($allow) {
        $allow($request, 'users.manage');
        $members->setStatus($members->member($user), MembershipStatus::Suspended);

        return response()->noContent();
    })->name('users.suspend');

    Route::post('users/{user}/reactivate', function (Request $request, string $user, ManageMembers $members) use ($allow) {
        $allow($request, 'users.manage');
        $members->setStatus($members->member($user), MembershipStatus::Active);

        return response()->noContent();
    })->name('users.reactivate');

    Route::delete('users/{user}', function (Request $request, string $user, ManageMembers $members) use ($allow) {
        $allow($request, 'users.manage');
        $members->setStatus($members->member($user), MembershipStatus::Removed);

        return response()->noContent();
    })->name('users.destroy');

    Route::get('invitations', function (Request $request) use ($allow, $invitationData) {
        $allow($request, 'users.view');

        return response()->json(['data' => UserInvitation::query()->whereIn('status', ['pending', 'accepted'])
            ->orderByRaw("status = 'pending' desc")->orderByDesc('id')->limit(200)->get()->map($invitationData)]);
    })->name('invitations.index');

    Route::post('invitations', function (Request $request, ManageInvitations $invitations) use ($allow, $invitationData) {
        $allow($request, 'users.invite');

        return response()->json(['data' => $invitationData($invitations->invite($request->validate([
            'email' => ['required', 'string', 'email', 'max:191'],
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'role' => ['required', 'string', 'max:125'],
            'sites' => ['nullable', 'array', 'max:100'],
            'sites.*' => ['string', 'ulid', 'distinct'],
            'message' => ['nullable', 'string', 'max:1000'],
        ]), $request->user()))], 201);
    })->name('invitations.store');

    Route::post('invitations/{invitation}/resend', function (Request $request, UserInvitation $invitation, ManageInvitations $invitations) use ($allow, $invitationData) {
        $allow($request, 'users.invite');

        return response()->json(['data' => $invitationData($invitations->resend($invitation, $request->user()))]);
    })->name('invitations.resend')->middleware('throttle:10,1');

    Route::delete('invitations/{invitation}', function (Request $request, UserInvitation $invitation, ManageInvitations $invitations) use ($allow) {
        $allow($request, 'users.invite');
        $invitations->revoke($invitation, $request->user());

        return response()->noContent();
    })->name('invitations.destroy');

    // Role × permission matrix (cahier §2): editable without touching the code.
    $roleData = fn (Role $role) => [
        'name' => $role->name,
        'label' => $role->label,
        'is_system' => $role->company_id === null,
        'permissions' => $role->permissions->pluck('name')->values(),
    ];

    Route::get('roles', function (Request $request, CompanyRoles $roles) use ($allow, $roleData) {
        $allow($request, 'users.view');

        return response()->json(['data' => [
            'roles' => $roles->query()->with('permissions:id,name')->orderByRaw('company_id is not null')->orderBy('id')->get()->map($roleData),
            'permissions' => collect($roles->grantablePermissions())->map(fn (string $name) => [
                'name' => $name, 'module' => strtok($name, '.'), 'scope' => PermissionCatalog::permissions()[$name][0]->value,
            ])->values(),
        ]]);
    })->name('roles.index');

    $saveRole = function (Request $request, CompanyRoles $roles, ?Role $role, AccessResolver $access, TenantContext $context) use ($roleData) {
        $input = $request->validate([
            'name' => [$role === null ? 'required' : 'exclude', 'string', 'max:60', 'regex:/^[a-z][a-z0-9_]*$/',
                Rule::unique('roles', 'name')->where(fn ($query) => $query->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $context->companyId())))],
            'label' => ['required', 'string', 'max:100'],
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', 'distinct', Rule::in($roles->grantablePermissions())],
        ]);

        $created = $role === null;
        $role ??= (new Role)->forceFill([
            'name' => $input['name'], 'guard_name' => 'web', 'company_id' => $context->companyId(), 'audience' => $roles->audience(), 'is_system' => false,
        ]);
        $role->forceFill(['label' => ['fr' => $input['label'], 'en' => $input['label']]])->save();
        $role->syncPermissions($input['permissions']);
        $access->flush($context->companyId());

        return response()->json(['data' => $roleData($role->load('permissions:id,name'))], $created ? 201 : 200);
    };

    // Only the company's own roles are editable here; system roles belong to the platform.
    $ownRole = fn (string $name, TenantContext $context) => Role::query()->where('company_id', $context->companyId())->where('name', $name)->firstOrFail();

    Route::post('roles', function (Request $request, CompanyRoles $roles, AccessResolver $access, TenantContext $context) use ($allow, $saveRole) {
        $allow($request, 'roles.manage');

        return $saveRole($request, $roles, null, $access, $context);
    })->name('roles.store');

    Route::put('roles/{role}', function (Request $request, string $role, CompanyRoles $roles, AccessResolver $access, TenantContext $context) use ($allow, $saveRole, $ownRole) {
        $allow($request, 'roles.manage');

        return $saveRole($request, $roles, $ownRole($role, $context), $access, $context);
    })->name('roles.update');

    Route::delete('roles/{role}', function (Request $request, string $role, AccessResolver $access, TenantContext $context) use ($allow, $ownRole) {
        $allow($request, 'roles.manage');
        $model = $ownRole($role, $context);
        if (DB::table('user_role_assignments')->where('role_id', $model->id)->exists()
            || DB::table('user_invitations')->where('role_id', $model->id)->where('status', 'pending')->exists()) {
            throw new BusinessRuleViolation('ROLE_IN_USE', 'This role is still assigned to users or pending invitations.');
        }
        $model->delete();
        $access->flush($context->companyId());

        return response()->noContent();
    })->name('roles.destroy');
});

/*
 * Platform: matrix of the system roles shared by every company.
 */
Route::middleware('auth:sanctum')->prefix('platform')->group(function () {
    Route::get('roles', function (Request $request) {
        abort_unless($request->user()->hasPermissionTo('platform.roles.manage'), 403);

        return response()->json(['data' => [
            'roles' => Role::query()->whereNull('company_id')->with('permissions:id,name')->orderBy('id')->get()
                ->map(fn (Role $role) => ['name' => $role->name, 'label' => $role->label, 'audience' => $role->audience, 'permissions' => $role->permissions->pluck('name')->values()]),
            'permissions' => collect(PermissionCatalog::permissions())->map(fn (array $definition, string $name) => [
                'name' => $name, 'module' => strtok($name, '.'), 'scope' => $definition[0]->value, 'audience' => $definition[1]->value,
            ])->values(),
        ]]);
    })->name('platform.roles.index');

    Route::put('roles/{role}', function (Request $request, string $role, AccessResolver $access) {
        abort_unless($request->user()->hasPermissionTo('platform.roles.manage'), 403);
        $model = Role::query()->whereNull('company_id')->where('name', $role)->firstOrFail();
        // A role only receives permissions of its own audience: a client role can never gain platform rights.
        $allowed = array_keys(array_filter(PermissionCatalog::permissions(), fn (array $definition) => $model->audience === Audience::Platform
            ? $definition[1] === Audience::Platform
            : $definition[0] !== PermissionScope::Platform && in_array($definition[1], [Audience::Any, $model->audience], true)));

        $model->syncPermissions($request->validate([
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', 'distinct', Rule::in($allowed)],
        ])['permissions']);
        $access->flushAll();

        return response()->noContent();
    })->name('platform.roles.update');
});
