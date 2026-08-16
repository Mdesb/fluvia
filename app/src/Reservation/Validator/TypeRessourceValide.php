<?php

declare(strict_types=1);

namespace App\Reservation\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * Contrainte validant `Ressource.codeType` contre le référentiel extensible (RG-M5-03/05, décision
 * structurante n°1 du plan) : format snake_case, pas de liste figée.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class TypeRessourceValide extends Constraint
{
    public string $message = 'Le type de ressource « {{ value }} » n\'est pas un code valide (snake_case, 2-40 caractères).';
}
