<?php

declare(strict_types=1);

namespace App\Stock\Validator;

use App\Stock\Entity\CatalogueFournisseur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/** RG-STOCK-03/CA-3 : refuse un second `principal=true` sur le même article de stock (pas de CHECK SQL partiel possible sous MariaDB). */
final class PrincipalUniqueValidator extends ConstraintValidator
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof PrincipalUnique) {
            throw new UnexpectedTypeException($constraint, PrincipalUnique::class);
        }

        if (!$value instanceof CatalogueFournisseur) {
            throw new UnexpectedValueException($value, CatalogueFournisseur::class);
        }

        if (!$value->isPrincipal() || $value->getArticleStock() === null) {
            return;
        }

        $existant = $this->em->getRepository(CatalogueFournisseur::class)->createQueryBuilder('c')
            ->andWhere('c.articleStock = :article')
            ->andWhere('c.principal = true')
            ->andWhere('c.id != :id')
            ->setParameter('article', $value->getArticleStock()->getId(), 'uuid')
            ->setParameter('id', $value->getId(), 'uuid')
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();

        if ($existant !== null) {
            $this->context->buildViolation($constraint->message)->addViolation();
        }
    }
}
