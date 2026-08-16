<?php

declare(strict_types=1);

namespace App\Reporting\Enum;

/** Cycle de vie d'un `RapportPlanifie` (RG-M7-06). */
enum EtatRapportPlanifie: string
{
    case Actif = 'actif';
    case Suspendu = 'suspendu';
}
