<?php

declare(strict_types=1);

namespace App\Offre\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * Deux saisons actives de MÊME priorité ne peuvent se chevaucher (CA-9). Les chevauchements de
 * priorités distinctes sont autorisés et départagés par la priorité au moment de la résolution
 * de prix (RG-M1-06 / CA-12).
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class SaisonSansChevauchement extends Constraint
{
    public string $message = 'Cette saison en chevauche une autre de même priorité : {{ saison }}.';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
