<?php

declare(strict_types=1);

namespace App\Fonctionnalite\Enum;

/**
 * Verticales métier du socle (§5 constitution.md) pilotant un preset de capacités
 * (`App\Fonctionnalite\Config\PresetVerticale`).
 */
enum Metier: string
{
    case Piscine = 'piscine';
    case Sport = 'sport';
    case Padel = 'padel';
    case Patinoire = 'patinoire';
    case Musee = 'musee';
}
