<?php

namespace App\Modules\Identity\Enums;

/**
 * Which kind of account a role or permission applies to.
 */
enum Audience: string
{
    case Platform = 'platform';
    case Industrial = 'industrial';
    case Provider = 'provider';
    case Brand = 'brand';
    case Any = 'any';
}
