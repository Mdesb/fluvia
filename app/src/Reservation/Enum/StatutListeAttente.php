<?php

declare(strict_types=1);

namespace App\Reservation\Enum;

/** Statut d'une inscription en liste d'attente (RG-M5-06). */
enum StatutListeAttente: string
{
    case EnAttente = 'en_attente';
    case Promue = 'promue';
    case Expiree = 'expiree';
    case Annulee = 'annulee';
}
