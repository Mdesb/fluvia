<?php

declare(strict_types=1);

namespace App\Dms\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Dms\Entity\Document;
use App\Dms\Entity\DocumentPublicLink;
use App\Dms\Entity\DocumentVersion;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Cloisonnement multi-entités des ressources `App\Dms` (RG-DMS-01, plan-dms.md §3.2) — copie stricte du
 * patron `App\Ocr\Doctrine\PerimetreOcrExtension`/`App\Finance\*\Doctrine\PerimetreFinanceExtension`
 * (jointure `Affectation` sur `establishment` + utilisateur courant, `Security::getUser()`, jamais un id
 * transmis par le client). `RetentionPolicy` **volontairement absent** (catalogue global, non
 * cloisonné, arbitrage D18 pt.7). S'applique uniquement à `GetCollection`/`Get` — échec fermé (404,
 * jamais une liste vide indiscernable d'un « pas encore de documents », CA-1).
 */
final class DmsScopeExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    /** @var array<class-string, list<string>> Relations à joindre depuis la racine jusqu'à « establishment ». */
    private const CHAINES = [
        Document::class => [],
        DocumentVersion::class => ['document'],
        DocumentPublicLink::class => ['document'],
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
            $nouvelAlias = 'dms_scope_' . $i;
            $queryBuilder->innerJoin($alias . '.' . $relation, $nouvelAlias);
            $alias = $nouvelAlias;
        }

        $queryBuilder
            ->innerJoin(
                Affectation::class,
                'aff_scope_dms',
                Join::WITH,
                sprintf(
                    'IDENTITY(aff_scope_dms.etablissement) = IDENTITY(%s.establishment) AND IDENTITY(aff_scope_dms.utilisateur) = :scope_dms_user',
                    $alias,
                ),
            )
            ->setParameter('scope_dms_user', $utilisateur->getId(), 'uuid')
            ->distinct();
    }
}
