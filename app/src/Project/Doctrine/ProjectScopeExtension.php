<?php

declare(strict_types=1);

namespace App\Project\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Project\Entity\Project;
use App\Project\Entity\ProjectTask;
use App\Securite\Service\ContexteEtablissement;
use Doctrine\ORM\QueryBuilder;

/**
 * Cloisonnement du module `Project` (RG-SOCLE-05).
 *
 * **Un projet appartient à l'établissement actif ; une tâche tient le sien de son projet.** C'est le
 * patron des entités satellites du dépôt — `Beneficiaire` → `client`, `GrilleTarifaire` → `produit`.
 *
 * **Sans cette extension, la collection des tâches serait lisible d'un établissement à l'autre.** Ce
 * n'est pas une hypothèse : le garde-fou de couverture de périmètre a attrapé exactement ce défaut sur
 * `CustomerContact` une heure plus tôt, le même jour. Une entité satellite exposée sans chemin de
 * jointure est le trou le plus facile à laisser, parce que **rien ne casse** — la liste est simplement
 * plus longue qu'elle ne devrait.
 *
 * > **Une fuite de cloisonnement ne produit pas d'erreur : elle produit des lignes en trop, et des
 * > lignes en trop ne se remarquent que si on les compte.**
 *
 * **Fermeture par défaut.** Sans établissement actif, rien — et non « tout ». Une liste vide se
 * remarque ; une liste inter-établissements a seulement l'air plus longue.
 */
final class ProjectScopeExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    /**
     * Chemin d'association menant au projet porteur de l'établissement — `null` quand la ressource
     * EST le projet.
     *
     * @var array<class-string, string|null>
     */
    private const CHEMINS = [
        Project::class => null,
        ProjectTask::class => 'project',
    ];

    public function __construct(
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
        if (!\array_key_exists($resourceClass, self::CHEMINS)) {
            return;
        }

        $alias = $queryBuilder->getRootAliases()[0];
        $actif = $this->contexte->idActif();

        if ($actif === null) {
            $queryBuilder->andWhere('1 = 0');

            return;
        }

        $aliasProjet = $alias;
        if (self::CHEMINS[$resourceClass] !== null) {
            $aliasProjet = 'perim_projet';
            $queryBuilder->innerJoin($alias . '.' . self::CHEMINS[$resourceClass], $aliasProjet);
        }

        $queryBuilder
            ->andWhere(sprintf('IDENTITY(%s.establishment) = :perim_projet_etab', $aliasProjet))
            // Type `uuid` explicite : sans lui, la comparaison ne compte rien et ne lève pas (D58) —
            // le tableau serait vide partout, ce qui ressemble à un module qu'on vient d'installer.
            ->setParameter('perim_projet_etab', $actif, 'uuid');
    }
}
