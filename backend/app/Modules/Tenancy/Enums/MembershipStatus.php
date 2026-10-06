<?php

namespace App\Modules\Tenancy\Enums;

enum MembershipStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Removed = 'removed';
}
