<?php

declare(strict_types=1);

namespace App\Personnel\Enum;

/** Statut d'un CreneauTravail (RG-PERSO-03). */
enum StatutCreneauTravail: string
{
    case Planifie = 'planifie';
    case Confirme = 'confirme';
    case Realise = 'realise';
    case Annule = 'annule';
}
