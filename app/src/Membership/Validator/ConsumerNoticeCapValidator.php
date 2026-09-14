<?php

declare(strict_types=1);

namespace App\Membership\Validator;

use App\Membership\Entity\Membership;
use App\Membership\Regime\SubscriberRegime;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Câblage mince : le régime et le seuil vivent dans `SubscriberRegime` (testé à nu) ; ce validateur ne
 * fait que lire le profil du payeur et poser la violation quand `preavisResiliationDepasse` est vrai.
 */
final class ConsumerNoticeCapValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ConsumerNoticeCap) {
            throw new UnexpectedTypeException($constraint, ConsumerNoticeCap::class);
        }

        if (!$value instanceof Membership) {
            return;
        }

        $regime = SubscriberRegime::pour($value->getPayeur()?->getType());
        if (!$regime->preavisResiliationDepasse($value->getPreavisResiliationJours())) {
            return;
        }

        $this->context->buildViolation($constraint->message)
            ->setParameter('{{ preavis }}', (string) $value->getPreavisResiliationJours())
            ->setParameter('{{ max }}', (string) $regime->preavisResiliationMaxJours())
            ->atPath('preavisResiliationJours')
            ->addViolation();
    }
}
