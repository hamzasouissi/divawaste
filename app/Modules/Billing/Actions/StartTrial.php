<?php

namespace App\Modules\Billing\Actions;

use App\Modules\Billing\Enums\SubscriptionStatus;
use App\Modules\Billing\Models\CompanySubscription;
use App\Modules\Common\Exceptions\BusinessRuleViolation;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Starts the 30-day free trial (M0-03). Called when the super-admin approves the company (blueprint §5.A).
 */
final class StartTrial
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Company $company, ?User $actor = null): CompanySubscription
    {
        return $this->context->runAs($company, fn () => DB::transaction(function () use ($company, $actor) {
            if (CompanySubscription::query()->where('is_current', true)->lockForUpdate()->exists()) {
                throw new BusinessRuleViolation('SUBSCRIPTION_EXISTS', 'The company already has a current subscription.');
            }

            $plan = DB::table('subscription_plans')->where('code', 'trial')->where('is_active', true)
                ->first(['id', 'code', 'trial_days', 'max_sites', 'max_users']);
            if ($plan === null) {
                throw new BusinessRuleViolation('TRIAL_PLAN_MISSING', 'No active trial plan is configured.');
            }

            $now = now();

            return CompanySubscription::query()->create([
                'subscription_plan_id' => $plan->id,
                'status' => SubscriptionStatus::Trialing,
                'currency_code' => $company->currency_code,
                'trial_starts_at' => $now,
                'trial_ends_at' => $now->addDays((int) $plan->trial_days),
                'starts_at' => $now,
                'is_current' => true,
                'price_snapshot' => ['plan' => $plan->code, 'max_sites' => $plan->max_sites, 'max_users' => $plan->max_users, 'components' => []],
                'created_by_user_id' => $actor?->id,
            ]);
        }));
    }
}
