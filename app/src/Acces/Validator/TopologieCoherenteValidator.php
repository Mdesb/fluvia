<?php

declare(strict_types=1);

namespace App\Acces\Validator;

use App\Acces\Entity\Equipement;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

final class TopologieCoherenteValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof TopologieCoherente) {
            throw new UnexpectedValueException($constraint, TopologieCoherente::class);
        }

        if (!$value instanceof Equipement) {
            return;
        }

        $controleur = $value->getControleur();
        if ($controleur === null) {
            $this->context->buildViolation($constraint->messageOrphelin)->atPath('controleur')->addViolation();

            return;
        }

        if ($value->getSens() === null) {
            $this->context->buildViolation($constraint->messageSens)->atPath('sens')->addViolation();
        }

        if (trim($controleur->getItboxRef()) === '') {
            $this->context->buildViolation($constraint->messageItbox)->atPath('controleur')->addViolation();
        }

        $espace = $controleur->getEspace();
        if ($espace === null || $espace->getSeuilFmi() < 0) {
            $this->context->buildViolation($constraint->messageSeuil)->atPath('controleur')->addViolation();
        }
    }
}
