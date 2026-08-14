<?php

declare(strict_types=1);

namespace App\Offre\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * Cohérence d'une carte multi-entrées (CA-8) : total crédité ≥ total payé, entiers > 0.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class CarteCoherente extends Constraint
{
    public string $message = 'Le nombre de passages crédités ({{ credite }}) doit être supérieur ou égal au nombre payé ({{ paye }}).';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
