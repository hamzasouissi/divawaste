<?php

namespace App\Modules\Identity\Enums;

/**
 * Level a permission is checked at: site permissions need a site, company ones a company-wide assignment.
 */
enum PermissionScope: string
{
    case Platform = 'platform';
    case Company = 'company';
    case Site = 'site';
}
