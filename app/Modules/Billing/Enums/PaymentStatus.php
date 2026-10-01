<?php

namespace App\Modules\Billing\Enums;

enum PaymentStatus: string
{
    case Pending = 'pending';
    case Validated = 'validated';
    case Rejected = 'rejected';
    case Refunded = 'refunded';
}
