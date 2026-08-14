<?php

declare(strict_types=1);

namespace App\Offre\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Offre\Entity\Produit;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Cloisonnement multi-entités des produits (RG-SOCLE-05, réutilise la stratégie du socle) :
 * un utilisateur ne voit que les produits rattachés à un établissement où il a une affectation.
 * Complète PerimetreEtablissementExtension du socle pour l'entité App\Offre\Entity\Produit.
 */
final class PerimetreProduitExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
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
        if ($resourceClass !== Produit::class) {
            return;
        }

        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            return;
        }

        $rootAlias = $queryBuilder->getRootAliases()[0];

        $queryBuilder
            ->innerJoin($rootAlias . '.etablissements', 'perim_etab')
            ->innerJoin(
                Affectation::class,
                'perim_aff',
                Join::WITH,
                'IDENTITY(perim_aff.etablissement) = perim_etab.id AND IDENTITY(perim_aff.utilisateur) = :perim_utilisateur'
            )
            ->setParameter('perim_utilisateur', $utilisateur->getId(), 'uuid')
            ->distinct();
    }
}
