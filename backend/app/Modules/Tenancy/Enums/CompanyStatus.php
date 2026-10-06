<?php

namespace App\Modules\Tenancy\Enums;

enum CompanyStatus: string
{
    case PendingVerification = 'pending_verification';
    case PendingApproval = 'pending_approval';
    case Active = 'active';
    case Rejected = 'rejected';
    case Suspended = 'suspended';
    case Closed = 'closed';
}
