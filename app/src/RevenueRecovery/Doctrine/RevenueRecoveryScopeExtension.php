<?php

declare(strict_types=1);

namespace App\RevenueRecovery\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\RevenueRecovery\Entity\RecoveryAttempt;
use App\RevenueRecovery\Entity\RecoveryCase;
use App\RevenueRecovery\Entity\RecoverySequence;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Cloisonnement établissement des ressources `App\RevenueRecovery` (D3/D8, RG-RR-07,
 * plan-revenue-recovery.md §0.6 pt.1) — copie stricte du patron
 * `App\Recouvrement\Doctrine\PerimetreRecouvrementExtension`/`App\SmartFlow\Doctrine\SmartFlowScopeExtension`
 * (jointure jusqu'à `.establishment` + `Affectation` de l'utilisateur courant, échec fermé : aucune ligne
 * hors périmètre n'est jamais rendue visible, 404 jamais 403).
 *
 * S'applique à `GetCollection`/`Get`, y compris `POST /revenue-recovery/cases/{id}/stop` (`read: true`,
 * §2 du plan) puisqu'il passe par le provider d'item standard.
 */
final class RevenueRecoveryScopeExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    /** @var array<class-string, list<string>> Relations à joindre depuis la racine jusqu'à « establishment ». */
    private const CHAINES = [
        RecoverySequence::class => [],
        RecoveryCase::class => [],
        RecoveryAttempt::class => ['recoveryCase'],
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
        $this->restrict($queryBuilder, $resourceClass);
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
        $this->restrict($queryBuilder, $resourceClass);
    }

    private function restrict(QueryBuilder $queryBuilder, string $resourceClass): void
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
            $nouvelAlias = 'revenue_recovery_scope_' . $i;
            $queryBuilder->innerJoin($alias . '.' . $relation, $nouvelAlias);
            $alias = $nouvelAlias;
        }

        $queryBuilder
            ->innerJoin(
                Affectation::class,
                'aff_scope_revenue_recovery',
                Join::WITH,
                sprintf(
                    'IDENTITY(aff_scope_revenue_recovery.etablissement) = IDENTITY(%s.establishment) AND IDENTITY(aff_scope_revenue_recovery.utilisateur) = :scope_revenue_recovery_user',
                    $alias,
                ),
            )
            ->setParameter('scope_revenue_recovery_user', $utilisateur->getId(), 'uuid')
            ->distinct();
    }
}
