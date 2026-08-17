<?php

declare(strict_types=1);

namespace App\Personnel\Validator;

use App\Personnel\Entity\Qualification;
use App\Personnel\Enum\TypeQualification;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

final class QualificationLibelleCoherentValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof QualificationLibelleCoherent) {
            throw new UnexpectedValueException($constraint, QualificationLibelleCoherent::class);
        }

        if (!$value instanceof Qualification) {
            return;
        }

        if ($value->getType() === TypeQualification::Autre && trim((string) $value->getLibelle()) === '') {
            $this->context->buildViolation($constraint->message)->atPath('libelle')->addViolation();
        }
    }
}
