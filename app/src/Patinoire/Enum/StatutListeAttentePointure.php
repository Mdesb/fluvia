<?php

declare(strict_types=1);

namespace App\Patinoire\Enum;

/** Statut d'une inscription en liste d'attente pointure (décision actée « pointure en rupture », §4.5). */
enum StatutListeAttentePointure: string
{
    case EnAttente = 'en_attente';
    case Proposee = 'proposee';
    case Honoree = 'honoree';
    case Expiree = 'expiree';
}
