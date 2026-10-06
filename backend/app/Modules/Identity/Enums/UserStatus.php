<?php

namespace App\Modules\Identity\Enums;

enum UserStatus: string
{
    case Active = 'active';
    case Disabled = 'disabled';
    case Anonymized = 'anonymized';
}
