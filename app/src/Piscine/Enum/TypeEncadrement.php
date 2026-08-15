<?php

declare(strict_types=1);

namespace App\Piscine\Enum;

/**
 * Type d'encadrement qualifié (RG-PISC-02, US-L6-04). Partagé entre `CreneauBassin.encadrantRequis`
 * (MNS|BNSSA|Aucune) et `QualificationEncadrant.type` (MNS|BNSSA|Autre) : les deux cas MNS/BNSSA
 * sont directement comparables, `Aucune` et `Autre` sont propres à chaque usage.
 */
enum TypeEncadrement: string
{
    case Mns = 'MNS';
    case Bnssa = 'BNSSA';
    case Aucune = 'aucune';
    case Autre = 'autre';
}
