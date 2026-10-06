<?php

namespace App\Modules\Billing\Actions;

use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Enums\PaymentStatus;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Payment;
use App\Modules\Billing\Support\Amount;
use App\Modules\Common\Exceptions\BusinessRuleViolation;
use App\Modules\Identity\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Super-admin validates or rejects a pending transfer (M0-04). Validation updates the invoice balance.
 * Runs inside the payment's tenant context (TenantContext::runAs).
 */
final class ReviewPayment
{
    public function validate(Payment $payment, User $reviewer): Payment
    {
        return $this->review($payment, $reviewer, function (Payment $payment) {
            $invoice = Invoice::query()->whereKey($payment->invoice_id)->lockForUpdate()->firstOrFail();
            $paid = Amount::add((string) $invoice->amount_paid, (string) $payment->amount);
            $fullyPaid = Amount::compare($paid, (string) $invoice->total_amount) >= 0;

            $invoice->forceFill([
                'amount_paid' => $paid,
                'status' => $fullyPaid ? InvoiceStatus::Paid : InvoiceStatus::PartiallyPaid,
                'paid_at' => $fullyPaid ? now() : null,
            ])->save();

            $payment->status = PaymentStatus::Validated;
        });
    }

    public function reject(Payment $payment, User $reviewer, string $reason): Payment
    {
        return $this->review($payment, $reviewer, function (Payment $payment) use ($reason) {
            $payment->status = PaymentStatus::Rejected;
            $payment->rejection_reason = $reason;
        });
    }

    /**
     * @param  callable(Payment): void  $apply
     */
    private function review(Payment $payment, User $reviewer, callable $apply): Payment
    {
        if (! $reviewer->hasPermissionTo('platform.payments.validate')) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($payment, $reviewer, $apply) {
            $payment = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($payment->status !== PaymentStatus::Pending) {
                throw new BusinessRuleViolation('PAYMENT_ALREADY_REVIEWED', 'This payment was already reviewed.');
            }

            $apply($payment);
            $payment->validated_at = now();
            $payment->validated_by_user_id = $reviewer->id;
            $payment->save();

            return $payment;
        });
    }
}
