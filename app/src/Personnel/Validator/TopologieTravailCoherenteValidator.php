<?php

declare(strict_types=1);

namespace App\Personnel\Validator;

use App\Personnel\Entity\CreneauTravail;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

final class TopologieTravailCoherenteValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof TopologieTravailCoherente) {
            throw new UnexpectedValueException($constraint, TopologieTravailCoherente::class);
        }

        if (!$value instanceof CreneauTravail) {
            return;
        }

        if ($value->getEtablissement() === null) {
            $this->context->buildViolation($constraint->messageEtablissement)->atPath('etablissement')->addViolation();
        }

        $debut = $value->getDebut();
        $fin = $value->getFin();
        if ($debut !== null && $fin !== null && $fin <= $debut) {
            $this->context->buildViolation($constraint->messageDates)->atPath('fin')->addViolation();
        }

        if ($value->getEffectifRequis() < 1) {
            $this->context->buildViolation($constraint->messageEffectif)->atPath('effectifRequis')->addViolation();
        }
    }
}
