<?php

declare(strict_types=1);

namespace App\Offre\Validator;

use App\Offre\Entity\CarteMultiEntrees;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

final class CarteCoherenteValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof CarteCoherente) {
            throw new UnexpectedValueException($constraint, CarteCoherente::class);
        }

        if (!$value instanceof CarteMultiEntrees) {
            return;
        }

        if ($value->getNbCredite() < $value->getNbPaye()) {
            $this->context->buildViolation($constraint->message)
                ->setParameter('{{ credite }}', (string) $value->getNbCredite())
                ->setParameter('{{ paye }}', (string) $value->getNbPaye())
                ->atPath('nbCredite')
                ->addViolation();
        }
    }
}
