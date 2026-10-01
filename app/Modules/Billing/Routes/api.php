<?php

use App\Modules\Billing\Actions\DeclareBankTransfer;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Requests\DeclareBankTransferRequest;
use App\Modules\Billing\Resources\PaymentResource;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'tenant'])->group(function () {
    Route::post('invoices/{invoice}/payments', fn (Invoice $invoice, DeclareBankTransferRequest $request, DeclareBankTransfer $action) => (new PaymentResource(
        $action->handle($invoice, $request->toData(), $request->user())
    ))->response()->setStatusCode(201))->name('invoices.payments.store');
});
