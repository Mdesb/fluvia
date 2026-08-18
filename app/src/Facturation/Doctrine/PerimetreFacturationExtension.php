<?php

declare(strict_types=1);

namespace App\Facturation\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Facturation\Entity\Facture;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Cloisonnement multi-entités de `Facture` (RG-SOCLE-05, `plan-facturation.md` §3) : un utilisateur ne
 * voit que les factures rattachées à un établissement où il possède au moins une affectation. Même
 * patron que `App\Vente\Doctrine\PerimetreVenteExtension`.
 *
 * ⚠ HYPOTHÈSE reprise de la spec (§3, `plan-facturation.md` §3) — restriction plus fine de l'agent de
 * caisse à ses propres ventes encaissées : **non implémentée** dans ce lot, le cloisonnement s'arrête
 * à l'établissement (comme RG-SOCLE-05).
 */
final class PerimetreFacturationExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    public function __construct(
        private readonly Security $security,
    ) {
    }

    public function applyToCollection(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        array $context = [],
    ): void {
        $this->restreindre($queryBuilder, $resourceClass);
    }

    /**
     * @param array<string, mixed> $identifiers
     * @param array<string, mixed> $context
     */
    public function applyToItem(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        array $identifiers,
        ?Operation $operation = null,
        array $context = [],
    ): void {
        $this->restreindre($queryBuilder, $resourceClass);
    }

    private function restreindre(QueryBuilder $queryBuilder, string $resourceClass): void
    {
        if ($resourceClass !== Facture::class) {
            return;
        }
        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            return;
        }

        $rootAlias = $queryBuilder->getRootAliases()[0];

        $queryBuilder
            ->innerJoin(
                Affectation::class,
                'aff_perimetre_facturation',
                Join::WITH,
                sprintf(
                    'IDENTITY(aff_perimetre_facturation.etablissement) = IDENTITY(%s.etablissement) AND IDENTITY(aff_perimetre_facturation.utilisateur) = :perimetre_facturation_utilisateur',
                    $rootAlias,
                ),
            )
            ->setParameter('perimetre_facturation_utilisateur', $utilisateur->getId(), 'uuid')
            ->distinct();
    }
}
