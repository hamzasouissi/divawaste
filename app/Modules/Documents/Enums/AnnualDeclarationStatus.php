<?php

namespace App\Modules\Documents\Enums;

enum AnnualDeclarationStatus: string
{
    case Draft = 'draft';
    case InReview = 'in_review';
    case Validated = 'validated';
    case Submitted = 'submitted';
}
