<?php

declare(strict_types=1);

namespace App\Sepa\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Utilisateur;
use App\Sepa\Entity\ConfigCreancierSepa;
use App\Sepa\Entity\LigneRemiseSepa;
use App\Sepa\Entity\MandatSepa;
use App\Sepa\Entity\RejetSepa;
use App\Sepa\Entity\RemiseSepa;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Cloisonnement multi-entités des ressources SEPA (RG-SOCLE-05), même patron que
 * `App\Sport\Doctrine\PerimetreSportExtension`. `MandatSepa`/`RemiseSepa`/`ConfigCreancierSepa`
 * portent directement leur `etablissement` (contrairement à l'ancien `MandatSepaFitness`, qui ne
 * l'obtenait qu'en remontant jusqu'à l'abonnement Sport).
 */
final class PerimetreSepaExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    /** @var array<class-string, list<string>> Relations à joindre depuis la racine jusqu'à « etablissement ». */
    private const CHAINES = [
        ConfigCreancierSepa::class => [],
        MandatSepa::class => [],
        RemiseSepa::class => [],
        LigneRemiseSepa::class => ['remise'],
        RejetSepa::class => ['ligne', 'remise'],
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
            $nouvelAlias = 'sepa_perimetre_' . $i;
            $queryBuilder->innerJoin($alias . '.' . $relation, $nouvelAlias);
            $alias = $nouvelAlias;
        }

        $queryBuilder
            ->innerJoin(
                Affectation::class,
                'aff_perimetre_sepa',
                Join::WITH,
                sprintf(
                    'IDENTITY(aff_perimetre_sepa.etablissement) = IDENTITY(%s.etablissement) AND IDENTITY(aff_perimetre_sepa.utilisateur) = :perimetre_sepa_utilisateur',
                    $alias,
                ),
            )
            ->setParameter('perimetre_sepa_utilisateur', $utilisateur->getId(), 'uuid')
            ->distinct();
    }
}
