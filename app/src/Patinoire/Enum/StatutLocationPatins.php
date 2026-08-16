<?php

declare(strict_types=1);

namespace App\Patinoire\Enum;

/** Cycle de vie d'une location de patins (RG-PAT-01/05, US-PATIN-02/03). */
enum StatutLocationPatins: string
{
    case EnCours = 'en_cours';
    case Retournee = 'retournee';
    case NonRendue = 'non_rendue';
}
