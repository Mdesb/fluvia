<?php

declare(strict_types=1);

namespace App\Stock\Validator;

use App\Securite\Service\ContexteEtablissement;
use App\Stock\Entity\ArticleStock;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/**
 * Vérifie RG-STOCK-02 en tenant compte de D41.
 *
 * **La subtilité est dans une seule ligne** : l'établissement à considérer est celui de l'article
 * s'il en a déjà un — cas d'une modification — et **sinon celui de la session serveur**, parce qu'à
 * la création il n'est pas encore estampillé. Sans ce repli, la contrainte s'évalue sur `null` et ne
 * protège plus rien.
 *
 * Le repli ne rouvre aucune porte : il lit la même source que l'estampilleur, `ContexteEtablissement`,
 * jamais le corps de la requête. Ce que la validation vérifie est donc exactement l'établissement dans
 * lequel l'article va être écrit.
 */
final class EanUniqueParEtablissementValidator extends ConstraintValidator
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ContexteEtablissement $contexte,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof EanUniqueParEtablissement) {
            throw new UnexpectedValueException($constraint, EanUniqueParEtablissement::class);
        }

        if (!$value instanceof ArticleStock || '' === $value->getCodeEAN()) {
            return;
        }

        $etablissement = $value->getEtablissement() ?? $this->contexte->etablissementActif();
        if (null === $etablissement) {
            // Sans établissement connu, l'estampilleur refusera de toute façon la création : inutile
            // d'ajouter une seconde erreur qui masquerait la vraie cause.
            return;
        }

        $existant = $this->em->createQuery(
            'SELECT a.id FROM ' . ArticleStock::class . ' a
             WHERE IDENTITY(a.etablissement) = :etablissement AND a.codeEAN = :ean',
        )
            ->setParameter('etablissement', $etablissement->getId(), 'uuid')
            ->setParameter('ean', $value->getCodeEAN())
            ->setMaxResults(2)
            ->getArrayResult();

        foreach ($existant as $ligne) {
            // Une modification retrouve sa propre ligne : ce n'est pas un doublon.
            if ((string) $ligne['id'] !== (string) $value->getId()) {
                $this->context->buildViolation($constraint->message)
                    ->atPath('codeEAN')
                    ->addViolation();

                return;
            }
        }
    }
}
