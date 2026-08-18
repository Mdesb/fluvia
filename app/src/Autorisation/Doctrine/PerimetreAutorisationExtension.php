<?php

declare(strict_types=1);

namespace App\Autorisation\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Autorisation\Entity\DemandeEscalade;
use App\Autorisation\Entity\LimiteAutorisation;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Cloisonnement multi-entités (RG-SOCLE-05, §1 plan) : restreint `LimiteAutorisation` et
 * `DemandeEscalade` par jointure `Affectation` sur `{root}.etablissement`, même patron exact que
 * `App\Vente\Doctrine\PerimetreVenteExtension`. `OperationSensible` n'est volontairement pas
 * cloisonnée (catalogue global, pas de champ établissement).
 */
final class PerimetreAutorisationExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    /** @var list<class-string> */
    private const CLASSES = [LimiteAutorisation::class, DemandeEscalade::class];

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
        if (!\in_array($resourceClass, self::CLASSES, true)) {
            return;
        }
        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            return;
        }

        $rootAlias = $queryBuilder->getRootAliases()[0];

        $queryBuilder
            ->innerJoin(
                Affectation::class,
                'aff_perimetre_autz',
                Join::WITH,
                sprintf(
                    'IDENTITY(aff_perimetre_autz.etablissement) = IDENTITY(%s.etablissement) AND IDENTITY(aff_perimetre_autz.utilisateur) = :perimetre_autz_utilisateur',
                    $rootAlias,
                ),
            )
            ->setParameter('perimetre_autz_utilisateur', $utilisateur->getId(), 'uuid')
            ->distinct();
    }
}
