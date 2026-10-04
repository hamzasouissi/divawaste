<?php

use App\Modules\Identity\Actions\AttemptLogin;
use App\Modules\Identity\Actions\ManageTwoFactor;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Notifications\VerifyEmailNotification;
use App\Modules\Identity\Resources\MeResource;
use App\Modules\Identity\Support\AccessSnapshot;
use App\Modules\Identity\Support\EmailVerification;
use App\Modules\Identity\Support\PasswordRules;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Resources\CompanyResource;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
        Company::query()->where('primary_contact_user_id', $user->id)->where('status', 'pending_verification')
            ->update(['status' => 'pending_approval', 'submitted_at' => now()]);
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
        $user->tokens()->when($current instanceof PersonalAccessToken, fn ($query) => $query->whereKeyNot($current->getKey()))->delete();

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
