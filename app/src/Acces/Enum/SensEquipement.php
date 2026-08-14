<?php

declare(strict_types=1);

namespace App\Acces\Enum;

/**
 * Sens d'un équipement (US-L3-01) : détermine l'impact sur la FMI (entrée = +1, sortie = −1,
 * RG-ACC-04). Un équipement bidirectionnel accepte les deux sens de passage.
 */
enum SensEquipement: string
{
    case Entree = 'entree';
    case Sortie = 'sortie';
    case Bidirectionnel = 'bidirectionnel';

    public function accepte(SensPassage $sens): bool
    {
        return match ($this) {
            self::Bidirectionnel => true,
            self::Entree => $sens === SensPassage::Entree,
            self::Sortie => $sens === SensPassage::Sortie,
        };
    }
}
