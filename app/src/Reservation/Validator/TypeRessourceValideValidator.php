<?php

declare(strict_types=1);

namespace App\Reservation\Validator;

use App\Reservation\Service\ReferentielTypeRessource;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class TypeRessourceValideValidator extends ConstraintValidator
{
    public function __construct(
        private readonly ReferentielTypeRessource $referentiel,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof TypeRessourceValide) {
            throw new UnexpectedTypeException($constraint, TypeRessourceValide::class);
        }

        if ($value === null || $value === '') {
            return;
        }

        if (!\is_string($value)) {
            throw new UnexpectedTypeException($value, 'string');
        }

        if (!$this->referentiel->estValide($value)) {
            $this->context->buildViolation($constraint->message)
                ->setParameter('{{ value }}', $value)
                ->addViolation();
        }
    }
}
