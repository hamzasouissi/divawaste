<?php

namespace App\Modules\Pickups\Enums;

enum PickupStatus: string
{
    case Draft = 'draft';
    case Requested = 'requested';
    case Confirmed = 'confirmed';
    case Refused = 'refused';
    case Collected = 'collected';
    case Received = 'received';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
