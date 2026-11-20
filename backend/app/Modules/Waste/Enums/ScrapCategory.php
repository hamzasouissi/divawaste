<?php

namespace App\Modules\Waste\Enums;

/**
 * The family of causes a scrap reason belongs to: the 6 M of a cause-and-effect (Ishikawa) diagram.
 */
enum ScrapCategory: string
{
    case Material = 'material';         // matière
    case Machine = 'machine';           // machine
    case Method = 'method';             // méthode
    case Manpower = 'manpower';         // main-d'œuvre
    case Measurement = 'measurement';   // mesure
    case Environment = 'environment';   // milieu
}
