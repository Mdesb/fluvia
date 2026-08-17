<?php

declare(strict_types=1);

namespace App\Personnel\Enum;

/** Type d'Absence légère (RG-PERSO-05/10 — pas de calcul de solde, SIRH externe). */
enum TypeAbsence: string
{
    case Conge = 'conge';
    case Maladie = 'maladie';
    case Formation = 'formation';
    case Autre = 'autre';
}
