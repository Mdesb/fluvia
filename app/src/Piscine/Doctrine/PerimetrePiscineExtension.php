<?php

declare(strict_types=1);

namespace App\Piscine\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Piscine\Entity\AffectationEncadrant;
use App\Piscine\Entity\Bassin;
use App\Piscine\Entity\BraceletEtanche;
use App\Piscine\Entity\Casier;
use App\Piscine\Entity\CautionCasier;
use App\Piscine\Entity\CreneauBassin;
use App\Piscine\Entity\CreneauPublic;
use App\Piscine\Entity\ForcageCasier;
use App\Piscine\Entity\JaugeGrandPublicCalculee;
use App\Piscine\Entity\LigneEau;
use App\Piscine\Entity\ParametrePiscineEtablissement;
use App\Piscine\Entity\Poss;
use App\Piscine\Entity\QualificationEncadrant;
use App\Piscine\Entity\RelanceCasier;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Cloisonnement multi-entités des ressources L6 (RG-SOCLE-05), même pattern que
 * `App\Acces\Doctrine\PerimetreAccesExtension` (L3, code réel lu). Pour les entités sans colonne
 * `etablissement` directe (dénormalisation non reprise pour tous les enfants, plan §1), la chaîne de
 * relations jusqu'à `etablissement` est jointe explicitement.
 */
final class PerimetrePiscineExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    /** @var array<class-string, list<string>> Relations à joindre depuis la racine jusqu'à « etablissement ». */
    private const CHAINES = [
        Poss::class => [],
        Bassin::class => [],
        QualificationEncadrant::class => [],
        Casier::class => [],
        ParametrePiscineEtablissement::class => [],
        LigneEau::class => ['bassin'],
        CreneauBassin::class => ['bassin'],
        CreneauPublic::class => ['creneauBassin', 'bassin'],
        JaugeGrandPublicCalculee::class => ['creneauBassin', 'bassin'],
        AffectationEncadrant::class => ['creneauBassin', 'bassin'],
        CautionCasier::class => ['casier'],
        RelanceCasier::class => ['casier'],
        ForcageCasier::class => ['casier'],
        BraceletEtanche::class => ['support'],
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
            $nouvelAlias = 'piscine_perimetre_' . $i;
            $queryBuilder->innerJoin($alias . '.' . $relation, $nouvelAlias);
            $alias = $nouvelAlias;
        }

        $queryBuilder
            ->innerJoin(
                Affectation::class,
                'aff_perimetre_piscine',
                Join::WITH,
                sprintf(
                    'IDENTITY(aff_perimetre_piscine.etablissement) = IDENTITY(%s.etablissement) AND IDENTITY(aff_perimetre_piscine.utilisateur) = :perimetre_piscine_utilisateur',
                    $alias,
                ),
            )
            ->setParameter('perimetre_piscine_utilisateur', $utilisateur->getId(), 'uuid')
            ->distinct();
    }
}
