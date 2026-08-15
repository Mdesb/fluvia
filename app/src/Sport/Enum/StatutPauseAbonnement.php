<?php

declare(strict_types=1);

namespace App\Sport\Enum;

/** Statut d'une demande de pause (US-SPORT-02). `Refusee` si impayé en cours (décision actée). */
enum StatutPauseAbonnement: string
{
    case Active = 'active';
    case Terminee = 'terminee';
    case Refusee = 'refusee';
}
