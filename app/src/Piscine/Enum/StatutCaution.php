<?php

declare(strict_types=1);

namespace App\Piscine\Enum;

/** Statut d'une caution de casier (décision actée « casier non rendu », US-L6-09). */
enum StatutCaution: string
{
    case Encaissee = 'encaissee';
    case Liberee = 'liberee';
    case Retenue = 'retenue';
}
