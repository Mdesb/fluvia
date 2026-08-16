<?php

declare(strict_types=1);

namespace App\Musee\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Musee\Entity\AllocationQuotaOTA;
use App\Musee\Entity\Audioguide;
use App\Musee\Entity\BasculeAudioguide;
use App\Musee\Entity\ContingentGratuite;
use App\Musee\Entity\DossierGroupeScolaire;
use App\Musee\Entity\Exposition;
use App\Musee\Entity\Gratuite;
use App\Musee\Entity\Guide;
use App\Musee\Entity\ParametreMuseeEtablissement;
use App\Musee\Entity\PartenaireOTA;
use App\Musee\Entity\PassAnnuel;
use App\Musee\Entity\PolitiqueDelestage;
use App\Musee\Entity\QualificationLangueGuide;
use App\Musee\Entity\ReservationOTA;
use App\Musee\Entity\Reversement;
use App\Musee\Entity\Salle;
use App\Musee\Entity\SousQuotaSalle;
use App\Musee\Entity\VisiteGuidee;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Cloisonnement multi-entités des ressources Musée (RG-SOCLE-05, patron `PerimetrePadelExtension`) :
 * un utilisateur ne voit que les objets rattachés à un établissement où il possède une affectation.
 */
final class PerimetreMuseeExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    /** @var array<class-string, list<array{property: string, alias: string}>> */
    private const JOINS = [
        QualificationLangueGuide::class => [['property' => 'guide', 'alias' => 'gd']],
        Gratuite::class => [['property' => 'dossier', 'alias' => 'dos']],
        ReservationOTA::class => [['property' => 'allocation', 'alias' => 'alloc']],
    ];

    /** @var array<class-string, string> Chemin final vers `etablissement` une fois les jointures faites. */
    private const CHEMIN_ETABLISSEMENT = [
        ParametreMuseeEtablissement::class => '{root}.etablissement',
        Salle::class => '{root}.etablissement',
        SousQuotaSalle::class => '{root}.etablissement',
        PolitiqueDelestage::class => '{root}.etablissement',
        Exposition::class => '{root}.etablissement',
        Audioguide::class => '{root}.etablissement',
        Guide::class => '{root}.etablissement',
        QualificationLangueGuide::class => 'gd.etablissement',
        VisiteGuidee::class => '{root}.etablissement',
        BasculeAudioguide::class => '{root}.etablissement',
        ContingentGratuite::class => '{root}.etablissement',
        DossierGroupeScolaire::class => '{root}.etablissement',
        Gratuite::class => 'dos.etablissement',
        PartenaireOTA::class => '{root}.etablissement',
        AllocationQuotaOTA::class => '{root}.etablissement',
        ReservationOTA::class => 'alloc.etablissement',
        Reversement::class => '{root}.etablissement',
        PassAnnuel::class => '{root}.etablissement',
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
        if (!isset(self::CHEMIN_ETABLISSEMENT[$resourceClass])) {
            return;
        }
        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            return;
        }

        $rootAlias = $queryBuilder->getRootAliases()[0];

        if (isset(self::JOINS[$resourceClass])) {
            foreach (self::JOINS[$resourceClass] as $jointure) {
                $queryBuilder->innerJoin($rootAlias . '.' . $jointure['property'], $jointure['alias']);
            }
        }

        $chemin = str_replace('{root}', $rootAlias, self::CHEMIN_ETABLISSEMENT[$resourceClass]);

        $queryBuilder
            ->innerJoin(
                Affectation::class,
                'aff_perimetre_musee',
                Join::WITH,
                sprintf(
                    'IDENTITY(aff_perimetre_musee.etablissement) = IDENTITY(%s) AND IDENTITY(aff_perimetre_musee.utilisateur) = :perimetre_musee_utilisateur',
                    $chemin,
                ),
            )
            ->setParameter('perimetre_musee_utilisateur', $utilisateur->getId(), 'uuid')
            ->distinct();
    }
}
