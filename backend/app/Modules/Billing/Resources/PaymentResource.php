<?php

namespace App\Modules\Billing\Resources;

use App\Modules\Billing\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Payment
 */
class PaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'method' => $this->method,
            'status' => $this->status,
            'amount' => $this->amount,
            'currency_code' => $this->currency_code,
            'bank_reference' => $this->bank_reference,
            'declared_paid_on' => $this->declared_paid_on?->toDateString(),
        ];
    }
}
