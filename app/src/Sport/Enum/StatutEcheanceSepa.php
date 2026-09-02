<?php

declare(strict_types=1);

namespace App\Sport\Enum;

/**
 * Statut d'une échéance de l'échéancier SEPA.
 *
 * ⚠ `Gelee` ET `Annulee` NE SONT PAS INTERCHANGEABLES, ET LA CONFUSION EST FACILE.
 *
 * `Gelee` = **pause** (RG-SPORT-05) : posée quand un adhérent demande une suspension, levée à la
 * reprise. L'échéance reviendra.
 *
 * `Annulee` = **abandon définitif**, avec son motif (`cancellationReason`). L'échéance ne reviendra
 * pas. Cet état manquait, et son absence coûtait cher sans faire de bruit : faute de mot pour dire
 * « abandonnée », les échéances abandonnées restaient `AVenir` indéfiniment, en se présentant comme
 * dues. Mesure du 02/09 : 38 d'entre elles, la plus ancienne de septembre 2025.
 */
enum StatutEcheanceSepa: string
{
    case AVenir = 'a_venir';
    case Prelevee = 'prelevee';
    case Rejetee = 'rejetee';
    case Gelee = 'gelee';
    case Annulee = 'annulee';
}
