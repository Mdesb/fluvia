<?php

declare(strict_types=1);

namespace App\Padel\Enum;

/** Statut d'un match de tournoi (US-PADEL-06). */
enum StatutMatchTournoi: string
{
    case AJouer = 'a_jouer';
    case Joue = 'joue';
}
