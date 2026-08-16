<?php

declare(strict_types=1);

namespace App\Padel\Enum;

/** Statut d'une caution matériel (patron `Piscine\Enum\StatutCaution`, dupliqué localement). */
enum StatutCautionMateriel: string
{
    case Encaissee = 'encaissee';
    case Liberee = 'liberee';
    case Retenue = 'retenue';
}
