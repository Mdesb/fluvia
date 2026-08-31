<?php

declare(strict_types=1);

namespace App\Import\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Import\Entity\ImportBatch;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Cloisonnement multi-entités d'`App\Import` (D3/D8, plan-import-i1.md §3 point 1) — copie du patron
 * `App\Dms\Doctrine\DmsScopeExtension`/`App\Finance\Treasury\Doctrine\PerimetreFinanceExtension`
 * (`establishment` **direct**, aucune jointure nécessaire pour `ImportBatch`). `ImportedEntityRef`
 * n'apparaît volontairement pas ici : elle n'est pas une `#[ApiResource]` (§2 point 4 du plan).
 *
 * Échec fermé : sans établissement actif, la collection est vide plutôt qu'inter-établissements (une
 * liste vide se remarque, une liste trop longue non).
 */
final class PerimetreImportExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    /** @var array<class-string, list<string>> Relations à joindre depuis la racine jusqu'à « establishment ». */
    private const CHAINES = [
        ImportBatch::class => [],
    ];

    public function __construct(
        private readonly Security $security,
        private readonly ContexteEtablissement $contexte,
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
            $nouvelAlias = 'import_perimetre_' . $i;
            $queryBuilder->innerJoin($alias . '.' . $relation, $nouvelAlias);
            $alias = $nouvelAlias;
        }

        $actif = $this->contexte->idActif();
        if ($actif === null) {
            $queryBuilder->andWhere('1 = 0');

            return;
        }

        $queryBuilder
            ->andWhere(sprintf('IDENTITY(%s.establishment) = :import_perimetre_actif', $alias))
            ->setParameter('import_perimetre_actif', $actif, 'uuid')
            ->distinct();
    }
}
