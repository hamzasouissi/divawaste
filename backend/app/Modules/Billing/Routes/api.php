<?php

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Billing\Actions\DeclareBankTransfer;
use App\Modules\Billing\Actions\ManageSubscription;
use App\Modules\Billing\Actions\ReviewPayment;
use App\Modules\Billing\Models\CompanySubscription;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\InvoiceItem;
use App\Modules\Billing\Models\Payment;
use App\Modules\Billing\Requests\DeclareBankTransferRequest;
use App\Modules\Billing\Resources\PaymentResource;
use App\Modules\Common\Exceptions\BusinessRuleViolation;
use App\Modules\Common\Support\DocumentLanguage;
use App\Modules\Notifications\Actions\NotifyUsers;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\TenantContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;

$invoiceData = fn (Invoice $invoice) => [
    'ulid' => $invoice->ulid, 'invoice_number' => $invoice->invoice_number, 'invoice_type' => $invoice->invoice_type, 'status' => $invoice->status,
    'issue_date' => $invoice->issue_date?->toDateString(), 'due_date' => $invoice->due_date?->toDateString(),
    'period_start' => $invoice->period_start?->toDateString(), 'period_end' => $invoice->period_end?->toDateString(),
    'currency_code' => $invoice->currency_code, 'subtotal_amount' => $invoice->subtotal_amount, 'tax_amount' => $invoice->tax_amount,
    'stamp_duty_amount' => $invoice->stamp_duty_amount, 'total_amount' => $invoice->total_amount, 'amount_paid' => $invoice->amount_paid,
    'pending_payment' => DB::table('payments')->where('invoice_id', $invoice->id)->where('status', 'pending')->exists(),
];

/*
 * M0-11: "Mon abonnement".
 */
Route::middleware(['auth:sanctum', 'tenant'])->group(function () use ($invoiceData) {
    $allow = fn (Request $request, string $permission) => abort_unless($request->user()->can($permission), 403);

    Route::get('subscription', function (Request $request, TenantContext $context) use ($allow) {
        $allow($request, 'subscription.view');
        $company = Company::query()->findOrFail($context->companyId());
        $subscription = CompanySubscription::query()->where('is_current', true)->first();
        $plans = DB::table('subscription_plans')->where('is_public', true)->where('is_active', true)->orderBy('sort_order')
            ->get(['id', 'code', 'name', 'billing_model', 'is_trial'])
            ->map(fn ($plan) => ['code' => $plan->code, 'name' => json_decode((string) $plan->name, true), 'billing_model' => $plan->billing_model, 'is_trial' => (bool) $plan->is_trial, 'id' => $plan->id]);

        return response()->json(['data' => [
            'current' => $subscription === null ? null : [
                'plan' => $plans->firstWhere('id', $subscription->subscription_plan_id)['code'] ?? null,
                'status' => $subscription->status, 'billing_interval' => $subscription->billing_interval,
                'sites_quantity' => $subscription->sites_quantity, 'unit_amount' => $subscription->price_snapshot['unit_amount'] ?? null,
                'currency_code' => $subscription->currency_code,
                'trial_ends_at' => $subscription->trial_ends_at?->toIso8601ZuluString(),
                'current_period_start' => $subscription->current_period_start?->toDateString(), 'current_period_end' => $subscription->current_period_end?->toDateString(),
                'cancel_at_period_end' => (bool) $subscription->cancel_at_period_end,
            ],
            'active_sites' => DB::table('sites')->where('company_id', $company->id)->where('is_active', true)->whereNull('deleted_at')->count(),
            'requested_plan' => $company->settings['requested_plan'] ?? null,
            'requested_sites' => $company->settings['requested_sites'] ?? null,
            'plans' => $plans->map(fn (array $plan) => array_diff_key($plan, ['id' => true]))->values(),
        ]]);
    })->name('subscription.show');

    // Change of formula or of number of sites: a request, activated by the platform at the agreed price (M0-04).
    Route::post('subscription/change-request', function (Request $request, TenantContext $context, NotifyUsers $notify) use ($allow) {
        $allow($request, 'subscription.manage');
        $input = $request->validate([
            'plan' => ['required', 'string', Rule::exists('subscription_plans', 'code')->where('is_public', true)->where('is_active', true)->where('is_trial', false)],
            'sites' => ['required', 'integer', 'between:1,500'],
        ]);
        $company = Company::query()->findOrFail($context->companyId());
        $company->forceFill(['settings' => ['requested_plan' => $input['plan'], 'requested_sites' => $input['sites']] + ($company->settings ?? [])])->save();
        $notify->toPlatformPermission('platform.invoices.manage', 'company.pending_review',
            ['company' => $company->legal_name, 'type' => 'abonnement '.$input['plan']], '/platform/companies/'.$company->ulid);

        return response()->noContent();
    })->name('subscription.change-request');

    Route::post('subscription/cancel', function (Request $request) use ($allow) {
        $allow($request, 'subscription.manage');
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:1000']])['reason'] ?? null;
        $subscription = CompanySubscription::query()->where('is_current', true)->firstOrFail();
        // Paid periods run to their end: the data stays available until then.
        $subscription->forceFill(['cancel_at_period_end' => true, 'cancellation_requested_at' => now(), 'cancellation_reason' => $reason])->save();

        return response()->noContent();
    })->name('subscription.cancel');

    Route::post('subscription/resume', function (Request $request) use ($allow) {
        $allow($request, 'subscription.manage');
        CompanySubscription::query()->where('is_current', true)->firstOrFail()
            ->forceFill(['cancel_at_period_end' => false, 'cancellation_requested_at' => null, 'cancellation_reason' => null])->save();

        return response()->noContent();
    })->name('subscription.resume');

    Route::get('invoices', function (Request $request) use ($allow, $invoiceData) {
        $allow($request, 'invoices.view');
        $invoices = Invoice::query()->where('status', '!=', 'draft')->orderByDesc('issue_date')->orderByDesc('id')
            ->paginate(min((int) $request->integer('per_page', 25), 100));

        return response()->json([
            'data' => collect($invoices->items())->map($invoiceData),
            'meta' => ['current_page' => $invoices->currentPage(), 'last_page' => $invoices->lastPage(), 'per_page' => $invoices->perPage(), 'total' => $invoices->total()],
        ]);
    })->name('invoices.index');

    // M7-04: invoice with the client's tax ID and the VAT of its country. Rendered from the frozen invoice data.
    Route::get('invoices/{invoice}/pdf', function (Request $request, Invoice $invoice) use ($allow) {
        $allow($request, 'invoices.view');
        abort_if($invoice->isDraft(), 404);

        return response(DocumentLanguage::during(DocumentLanguage::of($request), fn () => Pdf::loadView('pdf.invoice', [
            'invoice' => $invoice, 'items' => InvoiceItem::query()->where('invoice_id', $invoice->id)->orderBy('sort_order')->orderBy('id')->get(),
        ])->setPaper('a4')->output()), 200, [
            'Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$invoice->invoice_number.'.pdf"', 'Cache-Control' => 'private, no-store',
        ]);
    })->name('invoices.pdf');

    Route::post('invoices/{invoice}/payments', function (Invoice $invoice, DeclareBankTransferRequest $request, DeclareBankTransfer $action, NotifyUsers $notify, TenantContext $context) {
        $payment = $action->handle($invoice, $request->toData(), $request->user());
        $notify->toPlatformPermission('platform.payments.validate', 'payment.declared', [
            'company' => DB::table('companies')->where('id', $context->companyId())->value('legal_name'),
            'amount' => $payment->amount.' '.$payment->currency_code, 'invoice' => $invoice->invoice_number,
        ], '/platform/payments');

        return (new PaymentResource($payment))->response()->setStatusCode(201);
    })->name('invoices.payments.store');
});

/*
 * Platform: plan activation at the agreed price and review of declared bank transfers (M0-04).
 */
Route::middleware('auth:sanctum')->prefix('platform')->group(function () {
    $allow = fn (Request $request, string $permission) => abort_unless($request->user()->hasPermissionTo($permission), 403);

    Route::post('companies/{company}/subscription', function (Request $request, Company $company, ManageSubscription $subscriptions) use ($allow) {
        $allow($request, 'platform.invoices.manage');
        if ($company->status->value !== 'active') {
            throw new BusinessRuleViolation('COMPANY_NOT_ACTIVE', 'A plan can only be activated for an active company.');
        }
        $subscription = $subscriptions->activate($company, $request->validate([
            'plan' => ['required', 'string', Rule::exists('subscription_plans', 'code')->where('is_trial', false)],
            'billing_interval' => ['required', Rule::in(['monthly', 'yearly'])],
            'sites_quantity' => ['required', 'integer', 'between:1,500'],
            'unit_amount' => ['required', 'decimal:0,3', 'gt:0'],
        ]), $request->user());
        AuditLogger::record(['company_id' => $company->id, 'event' => 'subscription_activated', 'auditable_type' => 'company_subscriptions',
            'auditable_id' => $subscription->id, 'auditable_ulid' => $subscription->ulid, 'new_values' => $subscription->price_snapshot, 'tags' => ['platform_billing']]);

        return response()->json(['data' => ['ulid' => $subscription->ulid, 'status' => $subscription->status]], 201);
    })->name('platform.subscriptions.activate');

    Route::get('payments', function (Request $request, TenantContext $context) use ($allow) {
        $allow($request, 'platform.payments.validate');
        $status = $request->validate(['filter.status' => ['nullable', Rule::in(['pending', 'validated', 'rejected'])]])['filter']['status'] ?? 'pending';

        return response()->json(['data' => DB::table('payments as p')->join('invoices as i', 'i.id', '=', 'p.invoice_id')->join('companies as c', 'c.id', '=', 'p.company_id')
            ->where('p.status', $status)->orderBy('p.created_at')->limit(200)
            ->get(['p.ulid', 'p.status', 'p.amount', 'p.currency_code', 'p.bank_reference', 'p.declared_paid_on', 'p.rejection_reason',
                'i.invoice_number', 'i.total_amount', 'i.amount_paid', 'c.ulid as company', 'c.legal_name'])]);
    })->name('platform.payments.index');

    Route::post('payments/{payment}/review', function (Request $request, string $payment, ReviewPayment $review, TenantContext $context, NotifyUsers $notify) use ($allow) {
        $allow($request, 'platform.payments.validate');
        $input = $request->validate([
            'decision' => ['required', Rule::in(['validated', 'rejected'])],
            'reason' => ['nullable', 'required_if:decision,rejected', 'string', 'max:500'],
        ]);
        $row = DB::table('payments')->where('ulid', strtoupper($payment))->first(['id', 'company_id']);
        abort_if($row === null, 404);

        $context->runAs(Company::query()->findOrFail($row->company_id), function () use ($row, $input, $review, $request, $notify) {
            $model = Payment::query()->findOrFail($row->id);
            $model = $input['decision'] === 'validated' ? $review->validate($model, $request->user()) : $review->reject($model, $request->user(), $input['reason']);
            AuditLogger::record(['company_id' => (int) $row->company_id, 'event' => 'payment_'.$input['decision'], 'auditable_type' => 'payments', 'auditable_id' => $model->id,
                'auditable_ulid' => $model->ulid, 'new_values' => array_filter(['amount' => (string) $model->amount, 'reason' => $input['reason'] ?? null]), 'tags' => ['platform_billing']]);
            $notify->toCompanyPermission((int) $row->company_id, 'invoices.view', 'payment.reviewed', [
                'status' => $input['decision'] === 'validated' ? 'validé' : 'rejeté', 'amount' => $model->amount.' '.$model->currency_code,
                'invoice' => DB::table('invoices')->where('id', $model->invoice_id)->value('invoice_number'),
            ], '/subscription');
        });

        return response()->noContent();
    })->name('platform.payments.review');
});
