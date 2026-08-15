<?php

declare(strict_types=1);

namespace App\Sport\Enum;

/** Statut de l'abonnement fitness (RG-SPORT-04/05/06/07), pilote la projection d'accès (§0 du plan). */
enum StatutAbonnementFitness: string
{
    case Actif = 'actif';
    case Pause = 'pause';
    case Impaye = 'impaye';
    case Resilie = 'resilie';
}
