<?php

declare(strict_types=1);

namespace App\Stock\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Utilisateur;
use App\Stock\Entity\ArticleStock;
use App\Stock\Entity\CatalogueFournisseur;
use App\Stock\Entity\CommandeAchat;
use App\Stock\Entity\Fournisseur;
use App\Stock\Entity\ImputationLotStock;
use App\Stock\Entity\Inventaire;
use App\Stock\Entity\LigneCommandeAchat;
use App\Stock\Entity\LigneInventaire;
use App\Stock\Entity\LigneReceptionAchat;
use App\Stock\Entity\LotStock;
use App\Stock\Entity\MouvementStock;
use App\Stock\Entity\ParametrageStock;
use App\Stock\Entity\ReceptionAchat;
use App\Stock\Entity\TransfertStock;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Cloisonnement multi-entités de `App\Stock` (RG-SOCLE-05, RG-STOCK-13) : une seule classe, `match`
 * sur `$resourceClass`, même patron que `PerimetreProduitExtension`/`PerimetreCautionExtension`.
 * `TransfertStock` n'a pas de colonne établissement directe : filtré sur `articleStockSource.
 * etablissement OR articleStockDestination.etablissement` (§1.6/§5 du plan).
 */
final class PerimetreStockExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    /** @var array<class-string, list<string>> Relations à joindre depuis la racine jusqu'à « etablissement ». */
    private const CHAINES = [
        Fournisseur::class => [],
        CatalogueFournisseur::class => ['articleStock'],
        ArticleStock::class => [],
        ParametrageStock::class => [],
        CommandeAchat::class => [],
        LigneCommandeAchat::class => ['commandeAchat'],
        ReceptionAchat::class => [],
        LigneReceptionAchat::class => ['reception'],
        LotStock::class => [],
        MouvementStock::class => [],
        ImputationLotStock::class => ['mouvementStock'],
        Inventaire::class => [],
        LigneInventaire::class => ['inventaire'],
    ];

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
        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            return;
        }

        if ($resourceClass === TransfertStock::class) {
            $this->restreindreTransfert($queryBuilder, $utilisateur);

            return;
        }

        if (!isset(self::CHAINES[$resourceClass])) {
            return;
        }

        $alias = $queryBuilder->getRootAliases()[0];
        foreach (self::CHAINES[$resourceClass] as $i => $relation) {
            $nouvelAlias = 'stock_perimetre_' . $i;
            $queryBuilder->innerJoin($alias . '.' . $relation, $nouvelAlias);
            $alias = $nouvelAlias;
        }

        $queryBuilder
            ->innerJoin(
                Affectation::class,
                'stock_aff_perimetre',
                Join::WITH,
                sprintf(
                    'IDENTITY(stock_aff_perimetre.etablissement) = IDENTITY(%s.etablissement) AND IDENTITY(stock_aff_perimetre.utilisateur) = :stock_perimetre_utilisateur',
                    $alias,
                ),
            )
            ->setParameter('stock_perimetre_utilisateur', $utilisateur->getId(), 'uuid')
            ->distinct();
    }

    private function restreindreTransfert(QueryBuilder $queryBuilder, Utilisateur $utilisateur): void
    {
        $alias = $queryBuilder->getRootAliases()[0];
        $queryBuilder
            ->innerJoin($alias . '.articleStockSource', 'stock_transfert_source')
            ->innerJoin($alias . '.articleStockDestination', 'stock_transfert_destination')
            ->innerJoin(
                Affectation::class,
                'stock_transfert_aff',
                Join::WITH,
                '(IDENTITY(stock_transfert_aff.etablissement) = IDENTITY(stock_transfert_source.etablissement)'
                . ' OR IDENTITY(stock_transfert_aff.etablissement) = IDENTITY(stock_transfert_destination.etablissement))'
                . ' AND IDENTITY(stock_transfert_aff.utilisateur) = :stock_transfert_utilisateur',
            )
            ->setParameter('stock_transfert_utilisateur', $utilisateur->getId(), 'uuid')
            ->distinct();
    }
}
