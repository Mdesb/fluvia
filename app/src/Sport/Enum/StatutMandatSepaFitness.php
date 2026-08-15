<?php

declare(strict_types=1);

namespace App\Sport\Enum;

/** Statut du mandat SEPA fitness — révoqué à la date d'effet de la résiliation (RG-SPORT-06), pas avant. */
enum StatutMandatSepaFitness: string
{
    case Actif = 'actif';
    case Revoque = 'revoque';
}
