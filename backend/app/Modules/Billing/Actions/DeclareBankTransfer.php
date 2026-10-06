<?php

namespace App\Modules\Billing\Actions;

use App\Modules\Billing\DTOs\DeclareBankTransferData;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Payment;
use App\Modules\Common\Exceptions\BusinessRuleViolation;
use App\Modules\Identity\Models\User;

/**
 * Customer declares a bank transfer (M0-04); it stays pending until a super-admin validates it.
 */
final class DeclareBankTransfer
{
    public function handle(Invoice $invoice, DeclareBankTransferData $data, User $actor): Payment
    {
        if (! $invoice->status->acceptsPayments()) {
            throw new BusinessRuleViolation('INVOICE_NOT_PAYABLE', 'This invoice does not accept payments.');
        }

        return Payment::query()->create([
            'invoice_id' => $invoice->id,
            'method' => 'bank_transfer',
            'amount' => $data->amount,
            'currency_code' => $invoice->currency_code,
            'bank_reference' => $data->bankReference,
            'declared_paid_on' => $data->declaredPaidOn,
            'declared_by_user_id' => $actor->id,
        ]);
    }
}
