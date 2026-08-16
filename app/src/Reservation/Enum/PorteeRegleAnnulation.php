<?php

declare(strict_types=1);

namespace App\Reservation\Enum;

/** Portée d'application d'une RegleAnnulation (RG-M5-09) — la plus spécifique l'emporte. */
enum PorteeRegleAnnulation: string
{
    case Etablissement = 'etablissement';
    case TypeRessource = 'type_ressource';
    case Ressource = 'ressource';
    case Activite = 'activite';
}
