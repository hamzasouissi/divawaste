<?php

namespace App\Modules\Documents\Enums;

enum SignatureStatus: string
{
    case Pending = 'pending';
    case Signed = 'signed';
    case Declined = 'declined';
}
