<?php

namespace App\Modules\Tenancy\Http\Middleware;

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Enums\CompanyStatus;
use App\Modules\Tenancy\Enums\CompanyType;
use App\Modules\Tenancy\Enums\MembershipStatus;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Models\CompanyUser;
use App\Modules\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;

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

        if ($this->subscriptionLapsed($request, $company)) {
            $this->context->clear();

            throw new HttpException(Response::HTTP_PAYMENT_REQUIRED, 'The subscription has ended: the account is read-only until a plan is activated.');
        }

        try {
            return $next($request);
        } finally {
            $this->context->clear();
        }
    }

    /**
     * M0-03: when the trial or the plan has ended, an industrial keeps reading (and exporting) its data but can no
     * longer change it, apart from its account and subscription. Providers use the platform free of charge.
     * A company without any subscription row predates billing and is left alone.
     */
    private function subscriptionLapsed(Request $request, Company $company): bool
    {
        if ($request->isMethodSafe() || $company->company_type !== CompanyType::Industrial) {
            return false;
        }

        $route = (string) $request->route()?->getName();
        foreach (['subscription.', 'invoices.', 'me.', 'auth.', 'company.', 'reports.'] as $exempt) {
            if (str_starts_with($route, $exempt)) {
                return false;
            }
        }

        return in_array(
            DB::table('company_subscriptions')->where('company_id', $company->id)->where('is_current', true)->value('status'),
            ['expired', 'cancelled', 'suspended'],
            true,
        );
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
