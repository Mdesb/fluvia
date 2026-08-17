<?php

declare(strict_types=1);

namespace App\Personnel\Enum;

/** Statut de l'Employé (RG-PERSO-01). */
enum StatutEmploye: string
{
    case Actif = 'actif';
    case Suspendu = 'suspendu';
    case Sorti = 'sorti';
}
