<?php

declare(strict_types=1);

namespace App\Padel\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Padel\Entity\CautionMateriel;
use App\Padel\Entity\GrilleTarifaireTerrain;
use App\Padel\Entity\HistoriqueNiveauJoueur;
use App\Padel\Entity\InscriptionTournoi;
use App\Padel\Entity\LocationMateriel;
use App\Padel\Entity\MatchTournoi;
use App\Padel\Entity\NiveauJoueur;
use App\Padel\Entity\ParametragePadel;
use App\Padel\Entity\PlageHoraire;
use App\Padel\Entity\Poule;
use App\Padel\Entity\RelaisEclairageTerrain;
use App\Padel\Entity\ReservationPadel;
use App\Padel\Entity\TerrainPadel;
use App\Padel\Entity\Tournoi;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Cloisonnement multi-entités des ressources Padel (RG-SOCLE-05, patron `PerimetreReservationExtension`) :
 * un utilisateur ne voit que les objets rattachés à un établissement où il possède une affectation.
 */
final class PerimetrePadelExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    /** @var array<class-string, list<array{property: string, alias: string, target: class-string}>> */
    private const JOINS = [
        ReservationPadel::class => [['property' => 'reservation', 'alias' => 'res', 'target' => \App\Reservation\Entity\Reservation::class]],
        Poule::class => [['property' => 'tournoi', 'alias' => 'tour', 'target' => Tournoi::class]],
        InscriptionTournoi::class => [['property' => 'tournoi', 'alias' => 'tour', 'target' => Tournoi::class]],
        MatchTournoi::class => [['property' => 'tournoi', 'alias' => 'tour', 'target' => Tournoi::class]],
        LocationMateriel::class => [['property' => 'reservation', 'alias' => 'res', 'target' => \App\Reservation\Entity\Reservation::class]],
        CautionMateriel::class => [['property' => 'location', 'alias' => 'loc', 'target' => LocationMateriel::class], ['property' => 'loc.reservation', 'alias' => 'res', 'target' => \App\Reservation\Entity\Reservation::class]],
        HistoriqueNiveauJoueur::class => [['property' => 'niveauJoueur', 'alias' => 'niv', 'target' => NiveauJoueur::class]],
        RelaisEclairageTerrain::class => [['property' => 'terrain', 'alias' => 'terr', 'target' => TerrainPadel::class]],
    ];

    /** @var array<class-string, string> Chemin final vers `etablissement` une fois les jointures faites. */
    private const CHEMIN_ETABLISSEMENT = [
        TerrainPadel::class => 'ress.etablissement',
        ParametragePadel::class => '{root}.etablissement',
        PlageHoraire::class => '{root}.etablissement',
        GrilleTarifaireTerrain::class => 'ress.etablissement',
        ReservationPadel::class => 'res.etablissement',
        NiveauJoueur::class => '{root}.etablissement',
        HistoriqueNiveauJoueur::class => 'niv.etablissement',
        Tournoi::class => '{root}.etablissement',
        Poule::class => 'tour.etablissement',
        InscriptionTournoi::class => 'tour.etablissement',
        MatchTournoi::class => 'tour.etablissement',
        LocationMateriel::class => 'res.etablissement',
        CautionMateriel::class => 'res.etablissement',
        RelaisEclairageTerrain::class => 'ress.etablissement',
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

        // TerrainPadel/GrilleTarifaireTerrain/RelaisEclairageTerrain passent par `ressource` (socle).
        if (\in_array($resourceClass, [TerrainPadel::class], true)) {
            $queryBuilder->innerJoin($rootAlias . '.ressource', 'ress');
        } elseif (\in_array($resourceClass, [GrilleTarifaireTerrain::class, RelaisEclairageTerrain::class], true)) {
            $queryBuilder->innerJoin($rootAlias . '.terrain', 'terr')->innerJoin('terr.ressource', 'ress');
        } elseif (isset(self::JOINS[$resourceClass])) {
            foreach (self::JOINS[$resourceClass] as $jointure) {
                $chemin = str_contains($jointure['property'], '.') ? $jointure['property'] : $rootAlias . '.' . $jointure['property'];
                $queryBuilder->innerJoin($chemin, $jointure['alias']);
            }
        }

        $chemin = str_replace('{root}', $rootAlias, self::CHEMIN_ETABLISSEMENT[$resourceClass]);

        $queryBuilder
            ->innerJoin(
                Affectation::class,
                'aff_perimetre_padel',
                Join::WITH,
                sprintf(
                    'IDENTITY(aff_perimetre_padel.etablissement) = IDENTITY(%s) AND IDENTITY(aff_perimetre_padel.utilisateur) = :perimetre_padel_utilisateur',
                    $chemin,
                ),
            )
            ->setParameter('perimetre_padel_utilisateur', $utilisateur->getId(), 'uuid')
            ->distinct();
    }
}
