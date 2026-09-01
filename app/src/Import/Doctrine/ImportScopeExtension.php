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
 * Cloisonnement des lots de reprise (D3/D8), sur le patron de `Stay\Doctrine\StayScopeExtension`.
 *
 * **Un lot d'import est plus sensible que ce qu'il crée.** Il conserve le fichier source — des noms,
 * des dates de naissance, des courriels, parfois plusieurs milliers de personnes. Une lecture non
 * cloisonnée ne fuiterait pas quelques lignes : elle fuiterait le fichier d'abonnés entier d'un
 * autre exploitant.
 *
 * L'axe est **l'établissement actif**, comme partout ailleurs : le périmètre dit ce qu'on a le droit
 * de voir, l'actif dit ce qu'on regarde.
 */
final class ImportScopeExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    /** @var array<class-string, list<string>> Relations à joindre depuis la racine jusqu'à « establishment ». */
    public const CHAINS = [
        ImportBatch::class => [],
    ];

    public function __construct(
        private readonly Security $security,
        private readonly ContexteEtablissement $contexte,
    ) {
    }

    /** @param array<string, mixed> $context */
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
        if (!isset(self::CHAINS[$resourceClass])) {
            return;
        }

        if (!$this->security->getUser() instanceof Utilisateur) {
            return;
        }

        $alias = $queryBuilder->getRootAliases()[0];

        $actif = $this->contexte->idActif();
        if ($actif === null) {
            // Fermeture par défaut : une liste vide se remarque, une liste inter-établissements a
            // seulement l'air plus longue.
            $queryBuilder->andWhere('1 = 0');

            return;
        }

        $queryBuilder
            ->andWhere(sprintf('IDENTITY(%s.establishment) = :scope_import_actif', $alias))
            ->setParameter('scope_import_actif', $actif, 'uuid')
            ->distinct();
    }
}
