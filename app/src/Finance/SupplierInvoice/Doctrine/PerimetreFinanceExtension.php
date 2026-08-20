<?php

declare(strict_types=1);

namespace App\Finance\SupplierInvoice\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Finance\SupplierInvoice\Entity\SupplierInvoice;
use App\Finance\SupplierInvoice\Entity\SupplierInvoiceLine;
use App\Finance\SupplierInvoice\Entity\SupplierPayment;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Cloisonnement multi-entités de `App\Finance\SupplierInvoice` (D3/D8, §0.2 point 1 du plan) —
 * **copie stricte du patron** `App\Stock\Doctrine\PerimetreStockExtension` : jointure jusqu'à
 * `.establishment` + `Affectation` de l'utilisateur courant. Protège aussi les endpoints d'action
 * déclarés `read: true` (`/approve`, `/dispute`, `/resolve-dispute`, `/cancel`, `/credit-note`,
 * `/reconciliation`) puisqu'ils passent par le provider d'item standard.
 */
final class PerimetreFinanceExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    /** @var array<class-string, list<string>> Relations à joindre depuis la racine jusqu'à « establishment ». */
    private const CHAINES = [
        SupplierInvoice::class => [],
        SupplierInvoiceLine::class => ['supplierInvoice'],
        SupplierPayment::class => ['supplierInvoice'],
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

        if (!isset(self::CHAINES[$resourceClass])) {
            return;
        }

        $alias = $queryBuilder->getRootAliases()[0];
        foreach (self::CHAINES[$resourceClass] as $i => $relation) {
            $nouvelAlias = 'finance_perimetre_' . $i;
            $queryBuilder->innerJoin($alias . '.' . $relation, $nouvelAlias);
            $alias = $nouvelAlias;
        }

        $queryBuilder
            ->innerJoin(
                Affectation::class,
                'finance_aff_perimetre',
                Join::WITH,
                sprintf(
                    'IDENTITY(finance_aff_perimetre.etablissement) = IDENTITY(%s.establishment) AND IDENTITY(finance_aff_perimetre.utilisateur) = :finance_perimetre_utilisateur',
                    $alias,
                ),
            )
            ->setParameter('finance_perimetre_utilisateur', $utilisateur->getId(), 'uuid')
            ->distinct();
    }
}
