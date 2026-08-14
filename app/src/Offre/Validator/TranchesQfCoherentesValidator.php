<?php

declare(strict_types=1);

namespace App\Offre\Validator;

use App\Offre\Entity\TrancheQuotientFamilial;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/**
 * Valide l'ensemble des tranches de QF d'un type de tarif : après ajout/modif de la tranche
 * courante, l'ensemble trié par borne min doit être contigu, sans trou ni chevauchement (CA-6).
 */
final class TranchesQfCoherentesValidator extends ConstraintValidator
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof TranchesQfCoherentes) {
            throw new UnexpectedValueException($constraint, TranchesQfCoherentes::class);
        }

        if (!$value instanceof TrancheQuotientFamilial
            || $value->getTypeTarif() === null
            || $value->getBorneMin() === null
            || $value->getBorneMax() === null) {
            return;
        }

        /** @var list<TrancheQuotientFamilial> $existantes */
        $existantes = $this->em->getRepository(TrancheQuotientFamilial::class)
            ->findBy(['typeTarif' => $value->getTypeTarif()]);

        // Remplace/ajoute la tranche courante dans l'ensemble à valider.
        $ensemble = [];
        foreach ($existantes as $tranche) {
            if (!$tranche->getId()->equals($value->getId())) {
                $ensemble[] = $tranche;
            }
        }
        $ensemble[] = $value;

        usort($ensemble, static fn (TrancheQuotientFamilial $a, TrancheQuotientFamilial $b): int
            => (float) $a->getBorneMin() <=> (float) $b->getBorneMin());

        $precedente = null;
        foreach ($ensemble as $tranche) {
            if ($precedente !== null) {
                $finPrecedente = (float) $precedente->getBorneMax();
                $debut = (float) $tranche->getBorneMin();

                if ($debut < $finPrecedente) {
                    $this->context->buildViolation($constraint->chevauchementMessage)
                        ->atPath('borneMin')
                        ->addViolation();

                    return;
                }
                if ($debut > $finPrecedente) {
                    $this->context->buildViolation($constraint->trouMessage)
                        ->atPath('borneMin')
                        ->addViolation();

                    return;
                }
            }
            $precedente = $tranche;
        }
    }
}
