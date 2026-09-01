<?php

declare(strict_types=1);

namespace App\Dining\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Dining\Entity\DiningOrder;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Cloisonnement des ressources `App\Dining` (RG-SOCLE-05, D3) — meme patron que
 * `App\Stay\Doctrine\StayScopeExtension`.
 *
 * **Cette classe est le seul rempart des lectures en collection.** `DiningScopeGuard` protege les
 * ecritures et les entites resolues explicitement ; ici, c est la requete elle-meme qui est
 * restreinte, avant qu API Platform ne materialise quoi que ce soit. Les deux sont necessaires : un
 * `GetCollection` ne passe par aucun processor.
 *
 * **`CHAINS` doit lister toute entite du module exposee en `ApiResource`.** En oublier une ne casse
 * rien de visible — la ressource repond, simplement sans filtre d etablissement, c est-a-dire en
 * IDOR. `DiningScopeExtensionTest` compare cette table a ce qui est reellement expose, par reflexion :
 * l oubli devient un test rouge au lieu d une faille silencieuse.
 */
final class DiningScopeExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    /** @var array<class-string, list<string>> Relations a joindre depuis la racine jusqu a « establishment ». */
    public const CHAINS = [
        DiningOrder::class => [],
    ];

    public function __construct(private readonly Security $security)
    {
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
        if (!isset(self::CHAINS[$resourceClass])) {
            return;
        }

        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            return;
        }

        $alias = $queryBuilder->getRootAliases()[0];
        foreach (self::CHAINS[$resourceClass] as $i => $relation) {
            $nextAlias = 'dining_scope_' . $i;
            $queryBuilder->innerJoin($alias . '.' . $relation, $nextAlias);
            $alias = $nextAlias;
        }

        $queryBuilder
            ->innerJoin(
                Affectation::class,
                'aff_scope_dining',
                Join::WITH,
                sprintf(
                    'IDENTITY(aff_scope_dining.etablissement) = IDENTITY(%s.establishment) AND IDENTITY(aff_scope_dining.utilisateur) = :scope_dining_user',
                    $alias,
                ),
            )
            ->setParameter('scope_dining_user', $utilisateur->getId(), 'uuid')
            ->distinct();
    }
}
