<?php

namespace App\Modules\Sites\Enums;

enum SiteKind: string
{
    case Production = 'production';
    case Warehouse = 'warehouse';
    case Mixed = 'mixed';
}
