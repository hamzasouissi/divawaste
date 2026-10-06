<?php

namespace App\Modules\Documents\Enums;

enum DocumentType: string
{
    case RecyclingCertificate = 'recycling_certificate';
    case DestructionCertificate = 'destruction_certificate';
    case HazardousManifest = 'hazardous_manifest';
    case AnnualDeclaration = 'annual_declaration';
}
