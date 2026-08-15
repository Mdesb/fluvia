<?php

declare(strict_types=1);

namespace App\Sepa\Enum;

/** Statut d'un mandat SEPA (générique, reprend `App\Sport\Enum\StatutMandatSepaFitness`, plan §5). */
enum StatutMandatSepa: string
{
    case Actif = 'actif';
    case Revoque = 'revoque';
}
