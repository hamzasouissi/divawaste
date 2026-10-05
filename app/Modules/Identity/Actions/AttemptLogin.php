<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Notifications\AccountLockedNotification;
use App\Modules\Identity\Resources\MeResource;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * M0-09: password check, lockout after 5 consecutive failures, optional TOTP, then company binding.
 * Web clients ask for a session (Sanctum SPA), mobile/API clients get a bearer token (cahier §5).
 */
final class AttemptLogin
{
    public const MAX_FAILURES = 5;

    public const LOCK_MINUTES = 15;

    public const TOKEN_TTL_HOURS = 12;

    public function __construct(private readonly TwoFactorAuthenticationProvider $twoFactor) {}

    /**
     * @param  array{email: string, password: string, company?: ?string, code?: ?string, recovery_code?: ?string, mode?: ?string}  $input
     * @return array<string, mixed>
     */
    public function handle(array $input, Request $request): array
    {
        $user = User::query()->where('email', mb_strtolower(trim($input['email'])))->first();

        if ($user === null) {
            // Same work factor as a real check so unknown emails are not distinguishable by timing.
            Hash::check($input['password'], Hash::make('unknown-account'));

            throw new AuthenticationException;
        }

        if (! $user->isActive()) {
            throw new AuthenticationException;
        }

        if (! Hash::check($input['password'], $user->getAuthPassword())) {
            $this->recordFailure($user);

            throw new AuthenticationException;
        }

        if ($user->two_factor_confirmed_at !== null) {
            $code = $input['code'] ?? null;
            $recovery = $input['recovery_code'] ?? null;

            if ($code === null && $recovery === null) {
                return ['requires_two_factor' => true];
            }

            if (! $this->passesTwoFactor($user, $code, $recovery)) {
                $this->recordFailure($user);

                throw new AuthenticationException;
            }
        }

        $companies = $this->activeCompanies($user);
        $company = $this->resolveCompany($user, $companies, $input['company'] ?? null);

        if ($company === false) {
            return [
                'requires_company_selection' => true,
                'companies' => $companies->map(fn ($item) => ['ulid' => $item->ulid, 'legal_name' => $item->legal_name])->values(),
            ];
        }

        $user->forceFill([
            'failed_login_attempts' => 0,
            'locked_until' => null,
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
            'last_company_id' => $company->id ?? $user->last_company_id,
        ])->save();

        $session = ($input['mode'] ?? 'token') === 'session' && $request->hasSession();
        $token = null;

        if ($session) {
            Auth::guard('web')->login($user);
            $request->session()->regenerate();
            $request->session()->put('current_company_id', $company?->id);
        } else {
            $issued = $user->createToken('api', ['*'], now()->addHours(self::TOKEN_TTL_HOURS));
            $issued->accessToken->forceFill(['company_id' => $company?->id])->save();
            $token = $issued->plainTextToken;
        }

        return [
            'access_token' => $token,
            'token_type' => $token === null ? null : 'Bearer',
            'requires_company_selection' => false,
            'requires_two_factor' => false,
            'company' => $company === null ? null : ['ulid' => $company->ulid, 'legal_name' => $company->legal_name],
            'user' => (new MeResource($user->refresh()))->resolve($request),
        ];
    }

    /**
     * @return Collection<int, \stdClass>
     */
    public function activeCompanies(User $user): Collection
    {
        return DB::table('company_users as memberships')
            ->join('companies', 'companies.id', '=', 'memberships.company_id')
            ->where('memberships.user_id', $user->id)
            ->where('memberships.status', 'active')
            ->where('companies.status', 'active')
            ->orderBy('companies.legal_name')
            ->get(['companies.id', 'companies.ulid', 'companies.legal_name']);
    }

    /**
     * @param  Collection<int, \stdClass>  $companies
     * @return object|false|null company, false when the user must choose, null when there is none
     */
    private function resolveCompany(User $user, Collection $companies, ?string $requested): object|false|null
    {
        if ($requested !== null) {
            $company = $companies->firstWhere('ulid', strtoupper($requested));
            if ($company === null) {
                throw new AccessDeniedHttpException('No access to this company.');
            }

            return $company;
        }

        $company = $companies->firstWhere('id', (int) $user->last_company_id);
        $company ??= $companies->count() === 1 ? $companies->first() : null;

        return $company === null && $companies->count() > 1 ? false : $company;
    }

    private function passesTwoFactor(User $user, ?string $code, ?string $recovery): bool
    {
        if ($code !== null) {
            return $this->twoFactor->verify(Fortify::currentEncrypter()->decrypt($user->two_factor_secret), $code);
        }

        $match = collect($user->recoveryCodes())->first(fn (string $candidate) => hash_equals($candidate, (string) $recovery));
        if ($match === null) {
            return false;
        }

        $user->replaceRecoveryCode($match);

        return true;
    }

    private function recordFailure(User $user): void
    {
        $failures = $user->failed_login_attempts + 1;

        if ($failures < self::MAX_FAILURES) {
            $user->forceFill(['failed_login_attempts' => $failures])->save();

            return;
        }

        $user->forceFill(['failed_login_attempts' => 0, 'locked_until' => now()->addMinutes(self::LOCK_MINUTES)])->save();
        $user->notify(new AccountLockedNotification(self::LOCK_MINUTES));
    }
}
