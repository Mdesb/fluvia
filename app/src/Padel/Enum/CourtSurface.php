<?php

declare(strict_types=1);

namespace App\Padel\Enum;

/**
 * La surface de jeu d'un terrain reservable (R14).
 *
 * Distincte de `TypeTerrain` (couvert / decouvert), qui existait deja : un terrain en terre battue
 * peut etre indoor comme outdoor, et les deux informations interessent le joueur separement.
 *
 * ⚠ AUCUN CAS « INCONNU ». L'ignorance se dit par `null` sur la colonne, pas par une valeur qui
 * pretend etre une surface — sans quoi un filtre « terre battue » devrait apprendre a ecarter une
 * fausse surface, et un jour l'oublierait.
 *
 * Nom anglais (D5) : enum AJOUTE, donc classe et cas en anglais.
 */
enum CourtSurface: string
{
    case Clay = 'clay';
    case Hard = 'hard';
    case ArtificialGrass = 'artificial_grass';
    case Concrete = 'concrete';
    case Carpet = 'carpet';
}
