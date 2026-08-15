<?php

declare(strict_types=1);

namespace App\Piscine\Enum;

/**
 * Formule de calcul de la jauge grand public au prorata des lignes club/scolaire (US-L6-07, §4.7).
 * Seul le mode `Lignes` est calculé à ce lot ; `Surface`/`Forfait` sont des points d'extension
 * (⚠ formule non figée par les sources, plan §8 risque n°4).
 */
enum ModeProrata: string
{
    case Lignes = 'lignes';
    case Surface = 'surface';
    case Forfait = 'forfait';
}
