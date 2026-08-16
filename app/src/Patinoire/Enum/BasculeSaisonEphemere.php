<?php

declare(strict_types=1);

namespace App\Patinoire\Enum;

/** Mode de bascule de la fenêtre de vente d'une saison éphémère (RG-PAT-04, gap M1 signalé §4.9). */
enum BasculeSaisonEphemere: string
{
    case Manuelle = 'manuelle';
    case Automatique = 'automatique';
}
