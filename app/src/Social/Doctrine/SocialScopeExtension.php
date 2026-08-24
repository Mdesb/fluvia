<?php

declare(strict_types=1);

namespace App\Social\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Utilisateur;
use App\Social\Entity\SocialAccount;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Cloisonnement multi-établissements des ressources du module social (D3/D8) — même patron que
 * `App\Ocr\Doctrine\PerimetreOcrExtension` et `App\Sepa\Doctrine\PerimetreSepaExtension`.
 *
 * Le périmètre est dérivé de la session serveur (`Security::getUser()` + `Affectation`), jamais d'un
 * id transmis par le client (invariant noyau commun #1). L'extension s'applique aux collections comme
 * aux items : filtrer la liste sans filtrer l'accès direct laisserait exactement l'IDOR qu'on cherche
 * à fermer — c'est la forme des seize déjà trouvés ici.
 *
 * Un compte hors périmètre disparaît donc de la requête et l'API rend 404, jamais 403 : distinguer
 * « existe, pas à toi » de « n'existe pas » donnerait un oracle d'énumération des comptes sociaux des
 * autres établissements.
 */
final class SocialScopeExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    /**
     * Relations à joindre depuis la racine jusqu'à `establishment`. Vide = l'entité porte elle-même
     * son établissement.
     *
     * @var array<class-string, list<string>>
     */
    private const CHAINS = [
        SocialAccount::class => [],
    ];

    public function __construct(
        private readonly Security $security,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
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
        $user = $this->security->getUser();
        if (!$user instanceof Utilisateur) {
            return;
        }

        $alias = $queryBuilder->getRootAliases()[0];
        foreach (self::CHAINS[$resourceClass] as $i => $relation) {
            $newAlias = 'social_scope_' . $i;
            $queryBuilder->innerJoin($alias . '.' . $relation, $newAlias);
            $alias = $newAlias;
        }

        $queryBuilder
            ->innerJoin(
                Affectation::class,
                'aff_scope_social',
                Join::WITH,
                sprintf(
                    'IDENTITY(aff_scope_social.etablissement) = IDENTITY(%s.establishment) AND IDENTITY(aff_scope_social.utilisateur) = :social_scope_user',
                    $alias,
                ),
            )
            ->setParameter('social_scope_user', $user->getId(), 'uuid')
            ->distinct();
    }
}
