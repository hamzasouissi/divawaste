<?php

namespace App\Modules\Billing\Requests;

use App\Modules\Billing\DTOs\DeclareBankTransferData;
use Illuminate\Foundation\Http\FormRequest;

class DeclareBankTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('payments.declare');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'decimal:0,3', 'gt:0'],
            'bank_reference' => ['required', 'string', 'max:100'],
            'declared_paid_on' => ['required', 'date', 'before_or_equal:today'],
        ];
    }

    public function toData(): DeclareBankTransferData
    {
        return new DeclareBankTransferData(
            (string) $this->validated('amount'),
            (string) $this->validated('bank_reference'),
            (string) $this->validated('declared_paid_on'),
        );
    }
}
