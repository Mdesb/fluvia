<?php

declare(strict_types=1);

namespace App\Sport\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Utilisateur;
use App\Sport\Entity\AbonnementFitness;
use App\Sport\Entity\AlertePresenceIsolee;
use App\Sport\Entity\ConfigAccesNocturne;
use App\Sport\Entity\EcheanceSepa;
use App\Sport\Entity\EvenementSOS;
use App\Sport\Entity\IncidentPrelevement;
use App\Sport\Entity\MandatSepaFitness;
use App\Sport\Entity\MouvementComptableSepa;
use App\Sport\Entity\PauseAbonnement;
use App\Sport\Entity\PolitiqueAntiImpayes;
use App\Sport\Entity\Reengagement;
use App\Sport\Entity\RejetPrelevement;
use App\Sport\Entity\RemiseSepa;
use App\Sport\Entity\RepresentationSepa;
use App\Sport\Entity\Resiliation;
use App\Sport\Entity\StatutAccesFitness;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Cloisonnement multi-entités des ressources Sport (RG-SOCLE-05), même pattern que
 * `App\Piscine\Doctrine\PerimetrePiscineExtension`/`App\Acces\Doctrine\PerimetreAccesExtension`.
 */
final class PerimetreSportExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    /** @var array<class-string, list<string>> Relations à joindre depuis la racine jusqu'à « etablissement ». */
    private const CHAINES = [
        AbonnementFitness::class => [],
        PolitiqueAntiImpayes::class => [],
        RemiseSepa::class => [],
        MouvementComptableSepa::class => [],
        EcheanceSepa::class => ['abonnement'],
        MandatSepaFitness::class => ['abonnementRattache'],
        StatutAccesFitness::class => ['abonnement'],
        PauseAbonnement::class => ['abonnement'],
        Resiliation::class => ['abonnement'],
        Reengagement::class => ['ancienAbonnement'],
        IncidentPrelevement::class => ['abonnement'],
        RepresentationSepa::class => ['incident', 'abonnement'],
        RejetPrelevement::class => ['echeance', 'abonnement'],
        ConfigAccesNocturne::class => ['espaceAcces'],
        EvenementSOS::class => ['espaceAcces'],
        AlertePresenceIsolee::class => ['espaceAcces'],
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
            $nouvelAlias = 'sport_perimetre_' . $i;
            $queryBuilder->innerJoin($alias . '.' . $relation, $nouvelAlias);
            $alias = $nouvelAlias;
        }

        $queryBuilder
            ->innerJoin(
                Affectation::class,
                'aff_perimetre_sport',
                Join::WITH,
                sprintf(
                    'IDENTITY(aff_perimetre_sport.etablissement) = IDENTITY(%s.etablissement) AND IDENTITY(aff_perimetre_sport.utilisateur) = :perimetre_sport_utilisateur',
                    $alias,
                ),
            )
            ->setParameter('perimetre_sport_utilisateur', $utilisateur->getId(), 'uuid')
            ->distinct();
    }
}
