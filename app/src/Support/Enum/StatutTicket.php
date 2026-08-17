<?php

declare(strict_types=1);

namespace App\Support\Enum;

/** Cycle de vie d'un `TicketSupport` (RG-SUP-11). */
enum StatutTicket: string
{
    case Nouveau = 'nouveau';
    case EnCours = 'en_cours';
    case EnAttenteClient = 'en_attente_client';
    case Resolu = 'resolu';
    case Ferme = 'ferme';
}
