<?php

namespace App\Modules\Documents\Enums;

enum SignerParty: string
{
    case Producer = 'producer';
    case Transporter = 'transporter';
    case Receiver = 'receiver';
}
