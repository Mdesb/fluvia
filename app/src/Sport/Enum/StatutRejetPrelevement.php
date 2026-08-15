<?php

declare(strict_types=1);

namespace App\Sport\Enum;

/** Statut technique du retour brut banque (avant/après création de l'`IncidentPrelevement`). */
enum StatutRejetPrelevement: string
{
    case Nouveau = 'nouveau';
    case Traite = 'traite';
}
