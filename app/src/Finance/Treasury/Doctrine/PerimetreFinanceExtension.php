<?php

declare(strict_types=1);

namespace App\Finance\Treasury\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Finance\Treasury\Entity\BankAccount;
use App\Finance\Treasury\Entity\BankStatementImport;
use App\Finance\Treasury\Entity\BankStatementLine;
use App\Finance\Treasury\Entity\TreasurySettings;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Cloisonnement multi-entités de `App\Finance\Treasury` (D3/D8, §0.2 point 2 du plan) — **troisième
 * copie** du même patron déjà posé par `App\Finance\SupplierInvoice\Doctrine\PerimetreFinanceExtension`
 * (FIN-2) et `App\Finance\ExpenseReport\Doctrine\PerimetreFinanceExtension` (FIN-3) — recommandation de
 * promotion vers un service partagé réitérée une troisième fois (§7 point 3 du plan).
 *
 * `BankStatementLine => ['statementImport', 'bankAccount']` est une chaîne à **deux sauts**, supportée
 * nativement par la boucle `foreach` ci-dessous.
 *
 * `TreasurySettings` est ici un `establishment` **direct** (contrairement à `ReconciliationSettings` de
 * FIN-2, qui n'a qu'un `businessProfile`) — pas de patron `RESOURCES_VIA_PROFIL` nécessaire (§0.2 point
 * 2 du plan).
 */
final class PerimetreFinanceExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    /** @var array<class-string, list<string>> Relations à joindre depuis la racine jusqu'à « establishment ». */
    private const CHAINES = [
        BankAccount::class => [],
        BankStatementImport::class => ['bankAccount'],
        BankStatementLine::class => ['statementImport', 'bankAccount'],
        TreasurySettings::class => [],
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
        if (!isset(self::CHAINES[$resourceClass])) {
            return;
        }

        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            return;
        }

        $alias = $queryBuilder->getRootAliases()[0];
        foreach (self::CHAINES[$resourceClass] as $i => $relation) {
            $nouvelAlias = 'treasury_perimetre_' . $i;
            $queryBuilder->innerJoin($alias . '.' . $relation, $nouvelAlias);
            $alias = $nouvelAlias;
        }

        $condition = sprintf(
            'IDENTITY(treasury_aff_perimetre.etablissement) = IDENTITY(%s.establishment) AND IDENTITY(treasury_aff_perimetre.utilisateur) = :treasury_perimetre_utilisateur',
            $alias,
        );

        $queryBuilder
            ->innerJoin(Affectation::class, 'treasury_aff_perimetre', Join::WITH, $condition)
            ->setParameter('treasury_perimetre_utilisateur', $utilisateur->getId(), 'uuid')
            ->distinct();
    }
}
