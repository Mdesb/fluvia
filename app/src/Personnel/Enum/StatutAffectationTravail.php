<?php

declare(strict_types=1);

namespace App\Personnel\Enum;

/** Statut d'une AffectationTravail (RG-PERSO-04). */
enum StatutAffectationTravail: string
{
    case Planifiee = 'planifiee';
    case Confirmee = 'confirmee';
    case Realisee = 'realisee';
    case AbsenteRemplacee = 'absente_remplacee';
    case Annulee = 'annulee';
}
