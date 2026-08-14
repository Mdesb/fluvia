<?php

declare(strict_types=1);

namespace App\Offre\Enum;

/**
 * Périodicité de prélèvement d'une formule d'abonnement (RG-M1-03).
 */
enum PeriodiciteFormule: string
{
    case Mensuel = 'mensuel';
    case Annuel = 'annuel';
    case Personnalise = 'personnalise';
}
