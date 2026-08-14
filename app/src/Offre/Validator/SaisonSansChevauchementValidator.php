<?php

declare(strict_types=1);

namespace App\Offre\Validator;

use App\Offre\Entity\Saison;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

final class SaisonSansChevauchementValidator extends ConstraintValidator
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof SaisonSansChevauchement) {
            throw new UnexpectedValueException($constraint, SaisonSansChevauchement::class);
        }

        if (!$value instanceof Saison) {
            return;
        }

        if (!$value->isActif() || $value->getDateDebut() === null || $value->getDateFin() === null) {
            return;
        }

        /** @var list<Saison> $autres */
        $autres = $this->em->getRepository(Saison::class)->findBy(['actif' => true]);

        foreach ($autres as $autre) {
            if ($autre->getId()->equals($value->getId())) {
                continue;
            }
            if ($autre->getPriorite() !== $value->getPriorite()) {
                continue;
            }
            if ($value->chevauche($autre)) {
                $this->context->buildViolation($constraint->message)
                    ->setParameter('{{ saison }}', $autre->getNom())
                    ->atPath('dateDebut')
                    ->addViolation();

                return;
            }
        }
    }
}
