<?php

declare(strict_types=1);

namespace App\Caution\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Caution\Entity\Caution;
use App\Caution\Entity\GrilleRetenue;
use App\Caution\Entity\MouvementCaution;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Cloisonnement multi-entités du module caution générique (RG-SOCLE-05), même pattern que
 * `App\Piscine\Doctrine\PerimetrePiscineExtension` (code réel lu). `MouvementCaution` n'a pas de
 * colonne `etablissement` directe : la chaîne de relations passe par `caution`.
 */
final class PerimetreCautionExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    /** @var array<class-string, list<string>> Relations à joindre depuis la racine jusqu'à « etablissement ». */
    private const CHAINES = [
        Caution::class => [],
        GrilleRetenue::class => [],
        MouvementCaution::class => ['caution'],
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
            $nouvelAlias = 'caution_perimetre_' . $i;
            $queryBuilder->innerJoin($alias . '.' . $relation, $nouvelAlias);
            $alias = $nouvelAlias;
        }

        $queryBuilder
            ->innerJoin(
                Affectation::class,
                'aff_perimetre_caution',
                Join::WITH,
                sprintf(
                    'IDENTITY(aff_perimetre_caution.etablissement) = IDENTITY(%s.etablissement) AND IDENTITY(aff_perimetre_caution.utilisateur) = :perimetre_caution_utilisateur',
                    $alias,
                ),
            )
            ->setParameter('perimetre_caution_utilisateur', $utilisateur->getId(), 'uuid')
            ->distinct();
    }
}
