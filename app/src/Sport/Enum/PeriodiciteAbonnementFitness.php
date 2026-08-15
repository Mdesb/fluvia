<?php

declare(strict_types=1);

namespace App\Sport\Enum;

/** Cadence de collecte SEPA de l'abonnement fitness (spec §5) — distincte de `Formule.periodicite` (M1). */
enum PeriodiciteAbonnementFitness: string
{
    case Mensuel = 'mensuel';
    case Hebdomadaire = 'hebdomadaire';
}
