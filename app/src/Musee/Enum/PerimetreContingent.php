<?php

declare(strict_types=1);

namespace App\Musee\Enum;

/** Périmètre d'un contingent de gratuités scolaires (décision actée §4.5). */
enum PerimetreContingent: string
{
    case Exposition = 'exposition';
    case Creneau = 'creneau';
}
