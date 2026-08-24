<?php

declare(strict_types=1);

namespace App\Stay\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Utilisateur;
use App\Stay\Entity\Stay;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Cloisonnement des ressources `App\Stay` (RG-SOCLE-05, D3) — même patron que
 * `App\Recouvrement\Doctrine\PerimetreRecouvrementExtension`.
 *
 * **Cette classe est le seul rempart des lectures en collection.** `StayScopeGuard` protège les
 * écritures et les résolutions explicites ; ici, c'est la requête elle-même qui est restreinte, avant
 * qu'API Platform ne materialise quoi que ce soit. Les deux sont nécessaires : un `GetCollection` ne
 * passe par aucun processor, et un séjour absent de la requête ne peut pas fuiter par un oubli plus haut.
 *
 * **`CHAINES` doit lister toute entité du module exposée en `ApiResource`.** En oublier une ne casse
 * rien de visible — la ressource répond, simplement sans filtre, c'est-à-dire en IDOR. C'est pourquoi
 * `PerimetreStayExtensionTest` compare cette table à ce qui est réellement exposé, par réflexion :
 * l'oubli devient un test rouge au lieu d'une faille silencieuse.
 *
 * @see \App\Stay\Security\StayScopeGuard pour le versant écriture
 */
final class PerimetreStayExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    /** @var array<class-string, list<string>> Relations à joindre depuis la racine jusqu'à « establishment ». */
    public const CHAINES = [
        Stay::class => [],
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
            $nouvelAlias = 'stay_perimetre_' . $i;
            $queryBuilder->innerJoin($alias . '.' . $relation, $nouvelAlias);
            $alias = $nouvelAlias;
        }

        $queryBuilder
            ->innerJoin(
                Affectation::class,
                'aff_perimetre_stay',
                Join::WITH,
                sprintf(
                    'IDENTITY(aff_perimetre_stay.etablissement) = IDENTITY(%s.establishment) AND IDENTITY(aff_perimetre_stay.utilisateur) = :perimetre_stay_utilisateur',
                    $alias,
                ),
            )
            ->setParameter('perimetre_stay_utilisateur', $utilisateur->getId(), 'uuid')
            ->distinct();
    }
}
