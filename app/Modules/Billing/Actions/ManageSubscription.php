<?php

namespace App\Modules\Billing\Actions;

use App\Modules\Billing\Enums\SubscriptionStatus;
use App\Modules\Billing\Models\CompanySubscription;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\InvoiceItem;
use App\Modules\Common\Exceptions\BusinessRuleViolation;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Actions\NotifyUsers;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * M0-03 / M0-04 / M0-11 / M7-04. The price grid is still an open point of the cahier (§7): the platform enters the
 * agreed unit price when it activates a plan, and that price is frozen in the subscription. Every period is then
 * invoiced automatically from that snapshot, with the VAT and stamp duty of the client's country, to be paid by bank transfer.
 */
final class ManageSubscription
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly IssueInvoice $issue,
        private readonly NotifyUsers $notify,
    ) {}

    /**
     * Platform: replaces the current subscription (trial or paid) by a paid plan and issues its first invoice.
     *
     * @param  array{plan: string, billing_interval: string, sites_quantity: int, unit_amount: string}  $terms
     */
    public function activate(Company $company, array $terms, User $actor): CompanySubscription
    {
        return $this->context->runAs($company, function () use ($company, $terms, $actor) {
            [$subscription, $invoice, $planName] = DB::transaction(function () use ($company, $terms, $actor) {
                $plan = DB::table('subscription_plans')->where('code', $terms['plan'])->where('is_active', true)->where('is_trial', false)->first(['id', 'code', 'name', 'billing_model']);
                if ($plan === null) {
                    throw new BusinessRuleViolation('PLAN_UNAVAILABLE', 'This plan cannot be activated.');
                }

                $previous = CompanySubscription::query()->where('is_current', true)->lockForUpdate()->first();
                $start = now()->startOfDay();

                $subscription = new CompanySubscription([
                    'subscription_plan_id' => $plan->id, 'status' => SubscriptionStatus::Active, 'billing_interval' => $terms['billing_interval'],
                    'payment_method' => 'bank_transfer', 'currency_code' => $company->currency_code, 'sites_quantity' => $terms['sites_quantity'],
                    'starts_at' => $start, 'created_by_user_id' => $actor->id,
                    'price_snapshot' => ['plan' => $plan->code, 'billing_model' => $plan->billing_model, 'unit_amount' => $terms['unit_amount'],
                        'max_sites' => $plan->billing_model === 'per_site' ? $terms['sites_quantity'] : null],
                ]);
                // The unique (company, is_current) index allows a single current row: release the old one first.
                $previous?->forceFill(['is_current' => null, 'status' => $previous->status === SubscriptionStatus::Trialing ? SubscriptionStatus::Expired : SubscriptionStatus::Cancelled, 'ends_at' => now()])->save();
                $subscription->forceFill(['is_current' => true])->save();
                $previous?->forceFill(['replaced_by_subscription_id' => $subscription->id])->save();

                $settings = $company->settings ?? [];
                unset($settings['requested_plan'], $settings['requested_sites']);
                $company->forceFill(['settings' => $settings])->save();

                // The activation email below carries the first invoice: no separate "new invoice" message.
                $invoice = $this->billPeriod($subscription, $start, $actor, announce: false);

                return [$subscription, $invoice, json_decode((string) $plan->name, true)];
            });

            // M0-04 / M7-01: the client is told its plan is active, with the first invoice to pay.
            $this->notify->toCompanyPermission($company->id, 'invoices.view', 'subscription.activated', [
                'company' => $company->legal_name, 'plan' => $planName[$company->default_locale] ?? $planName['fr'] ?? $terms['plan'],
                'start' => $subscription->starts_at->format('d/m/Y'), 'invoice' => $invoice->invoice_number,
                'amount' => $invoice->total_amount.' '.$invoice->currency_code, 'due_date' => $invoice->due_date->format('d/m/Y'),
            ], '/subscription');

            return $subscription;
        });
    }

    /**
     * Invoices one period of an active subscription and moves its period forward. Runs in the company's context.
     */
    public function billPeriod(CompanySubscription $subscription, CarbonInterface $periodStart, ?User $actor = null, bool $announce = true): Invoice
    {
        return DB::transaction(function () use ($subscription, $periodStart, $actor, $announce) {
            $company = Company::query()->findOrFail($subscription->company_id);
            $periodEnd = ($subscription->billing_interval === 'yearly' ? $periodStart->copy()->addYear() : $periodStart->copy()->addMonth())->subDay();
            $snapshot = $subscription->price_snapshot;
            $perSite = ($snapshot['billing_model'] ?? null) === 'per_site';
            $taxes = DB::table('tax_rates')->where('country_id', $company->country_id)->where('is_active', true)->where('applies_to', 'subscription')
                ->whereDate('valid_from', '<=', $periodStart)->where(fn ($query) => $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $periodStart))
                ->orderByDesc('valid_from')->get(['id', 'tax_type', 'rate_pct', 'fixed_amount'])->unique('tax_type')->keyBy('tax_type');

            $invoice = Invoice::query()->create([
                'company_subscription_id' => $subscription->id, 'currency_code' => $subscription->currency_code,
                'period_start' => $periodStart, 'period_end' => $periodEnd, 'due_date' => now()->addDays(30)->startOfDay(),
                'stamp_duty_amount' => (string) ($taxes['stamp_duty']->fixed_amount ?? '0.000'),
            ]);
            InvoiceItem::query()->create([
                'invoice_id' => $invoice->id, 'item_type' => $perSite ? 'site' : 'subscription',
                'description' => sprintf('Abonnement DivaWaste %s — du %s au %s', $perSite ? 'par site' : 'par volume', $periodStart->format('d/m/Y'), $periodEnd->format('d/m/Y')),
                'quantity' => $perSite ? $subscription->sites_quantity : 1, 'unit_amount' => $snapshot['unit_amount'],
                'tax_rate_id' => $taxes['vat']->id ?? null, 'tax_rate_pct' => (string) ($taxes['vat']->rate_pct ?? '0.000'),
                'usage_metric' => $perSite ? 'active_sites' : null, 'period_start' => $periodStart, 'period_end' => $periodEnd,
            ]);
            $invoice = $this->issue->handle($invoice, $actor);

            $subscription->forceFill(['current_period_start' => $periodStart, 'current_period_end' => $periodEnd])->save();
            if ($announce) {
                $this->notify->toCompanyPermission($company->id, 'invoices.view', 'invoice.issued', [
                    'invoice' => $invoice->invoice_number, 'amount' => $invoice->total_amount.' '.$invoice->currency_code, 'due_date' => $invoice->due_date->format('d/m/Y'),
                ], '/subscription');
            }

            return $invoice;
        });
    }

    /**
     * Daily task: renew the periods that ended, stop what was cancelled, warn before a trial ends and expire it after.
     *
     * @return array{renewed: int, cancelled: int, trial_warnings: int, trials_expired: int}
     */
    public function runDaily(): array
    {
        $today = now()->startOfDay();
        $result = ['renewed' => 0, 'cancelled' => 0, 'trial_warnings' => 0, 'trials_expired' => 0];
        $subscriptions = $this->context->runAsSystem('daily subscription billing', fn () => CompanySubscription::query()->where('is_current', true)
            ->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::Trialing])->get());

        foreach ($subscriptions as $subscription) {
            $company = Company::query()->find($subscription->company_id);
            if ($company === null) {
                continue;
            }

            $this->context->runAs($company, function () use ($subscription, $today, &$result) {
                if ($subscription->status === SubscriptionStatus::Trialing) {
                    $daysLeft = (int) $today->diffInDays($subscription->trial_ends_at->copy()->startOfDay(), false);
                    if ($daysLeft < 0) {
                        $subscription->forceFill(['status' => SubscriptionStatus::Expired, 'ends_at' => now()])->save();
                        $result['trials_expired']++;
                    } elseif ($daysLeft === 7 || $daysLeft === 1) {
                        $this->notify->toCompanyPermission($subscription->company_id, 'subscription.manage', 'subscription.trial_ending',
                            ['date' => $subscription->trial_ends_at->format('d/m/Y'), 'days' => $daysLeft], '/subscription');
                        $result['trial_warnings']++;
                    }

                    return;
                }

                if ($subscription->current_period_end === null || $subscription->current_period_end->greaterThanOrEqualTo($today)) {
                    return;
                }
                if ($subscription->cancel_at_period_end) {
                    $subscription->forceFill(['status' => SubscriptionStatus::Cancelled, 'cancelled_at' => now(), 'ends_at' => now()])->save();
                    $result['cancelled']++;

                    return;
                }
                // The next period starts the day after the previous one ended, even if the task ran late: no gap, no overlap.
                $this->billPeriod($subscription, $subscription->current_period_end->copy()->addDay());
                $result['renewed']++;
            });
        }

        return $result;
    }
}
