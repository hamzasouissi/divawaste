<?php

namespace App\Modules\Sync\Enums;

enum SyncOperationStatus: string
{
    case Applied = 'applied';
    case Rejected = 'rejected';
    case Conflict = 'conflict';
}
