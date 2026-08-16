<?php

declare(strict_types=1);

namespace App\Reporting\Enum;

/** Périodicité d'un `RapportPlanifie` (§4.6 spec). */
enum PeriodiciteRapport: string
{
    case Quotidienne = 'quotidienne';
    case Hebdomadaire = 'hebdomadaire';
    case Mensuelle = 'mensuelle';
}
