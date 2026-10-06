<?php

namespace App\Modules\Sites\Enums;

enum AlertLevel: string
{
    case Warning = 'warning';
    case Exceeded = 'exceeded';
}
