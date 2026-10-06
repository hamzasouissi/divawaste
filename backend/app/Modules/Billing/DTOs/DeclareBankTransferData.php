<?php

namespace App\Modules\Billing\DTOs;

final readonly class DeclareBankTransferData
{
    public function __construct(
        public string $amount,
        public string $bankReference,
        public string $declaredPaidOn,
    ) {}
}
