<?php

declare(strict_types=1);

namespace App\Padel\Enum;

/** Cycle de vie d'un tournoi. */
enum StatutTournoi: string
{
    case OuvertInscriptions = 'ouvert_inscriptions';
    case EnCours = 'en_cours';
    case Termine = 'termine';
}
