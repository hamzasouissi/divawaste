<?php

namespace App\Modules\Documents\Enums;

enum DocumentStatus: string
{
    case Draft = 'draft';
    case PendingSignatures = 'pending_signatures';
    case Issued = 'issued';
    case Superseded = 'superseded';
    case Cancelled = 'cancelled';
}
