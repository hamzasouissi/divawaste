<?php

namespace App\Modules\Tenancy\Http\Middleware;

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Enums\CompanyStatus;
use App\Modules\Tenancy\Enums\MembershipStatus;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Models\CompanyUser;
use App\Modules\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Resolves the tenant from the token binding (PWA/API) or the session (web SPA), never from a
 * client-supplied header, then checks the active membership and the company status.
 *
 * Usage: `tenant` (active companies) or `tenant:active,pending_approval`.
 */
final class EstablishTenantContext
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next, string ...$allowedStatuses): Response
    {
        $user = $request->user();
        $companyId = $user instanceof User ? $this->resolveCompanyId($request, $user) : null;

        $company = $companyId === null ? null : Company::query()
            ->select(['id', 'ulid', 'company_type', 'status'])
            ->find($companyId);

        if ($company === null || ! $this->isActiveMember($company, $user)) {
            throw new AccessDeniedHttpException('No access to a company.');
        }

        $allowed = $allowedStatuses === [] ? [CompanyStatus::Active->value] : $allowedStatuses;
        if (! in_array($company->status->value, $allowed, true)) {
            throw new AccessDeniedHttpException('Company is not active.');
        }

        $this->context->set($company);

        try {
            return $next($request);
        } finally {
            $this->context->clear();
        }
    }

    private function resolveCompanyId(Request $request, User $user): ?int
    {
        $token = $user->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            return $token->company_id === null ? null : (int) $token->company_id;
        }

        $sessionCompany = $request->hasSession() ? $request->session()->get('current_company_id') : null;

        return is_numeric($sessionCompany) ? (int) $sessionCompany : null;
    }

    private function isActiveMember(Company $company, mixed $user): bool
    {
        return $user instanceof User && $this->context->runAs($company, fn () => CompanyUser::query()
            ->where('user_id', $user->id)
            ->where('status', MembershipStatus::Active)
            ->exists());
    }
}
