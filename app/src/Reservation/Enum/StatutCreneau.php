<?php

declare(strict_types=1);

namespace App\Reservation\Enum;

/** Statut d'un Créneau (cahier §6, RG-M5-01/03). */
enum StatutCreneau: string
{
    case Planifie = 'planifie';
    case Complet = 'complet';
    case Termine = 'termine';
    case Annule = 'annule';
}
