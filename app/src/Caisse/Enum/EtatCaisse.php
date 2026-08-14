<?php

declare(strict_types=1);

namespace App\Caisse\Enum;

/**
 * État d'une caisse physique (cahier M2-01) : 🟢 ouverte → 🟡 en cours de fermeture → 🔒 sécurisée.
 * Une caisse sécurisée exige le code régisseur pour être rouverte (CA-2).
 */
enum EtatCaisse: string
{
    case Ouverte = 'ouverte';
    case EnFermeture = 'en_fermeture';
    case Securisee = 'securisee';
}
