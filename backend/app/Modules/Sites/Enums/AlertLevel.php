<?php

namespace App\Modules\Sites\Enums;

enum AlertLevel: string
{
    case Warning = 'warning';
    case Exceeded = 'exceeded';
    /** Below the limit on the weight that is known, with lots nobody weighed: not a limit anyone can call respected. */
    case Unverifiable = 'unverifiable';
}
