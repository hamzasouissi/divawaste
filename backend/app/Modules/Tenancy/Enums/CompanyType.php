<?php

namespace App\Modules\Tenancy\Enums;

enum CompanyType: string
{
    case Industrial = 'industrial';
    case Provider = 'provider';
    case Brand = 'brand';
}
