<?php

declare(strict_types=1);

namespace App\Piscine\Enum;

/** Statut d'un créneau bassin (extension provisoire, gap M5, §1.3 du plan). */
enum StatutCreneauBassin: string
{
    case Brouillon = 'brouillon';
    case Valide = 'valide';
    case Annule = 'annule';
}
