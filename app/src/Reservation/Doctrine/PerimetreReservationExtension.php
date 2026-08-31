<?php

declare(strict_types=1);

namespace App\Reservation\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Reservation\Entity\Activite;
use App\Reservation\Entity\Creneau;
use App\Reservation\Entity\DisponibiliteRessource;
use App\Reservation\Entity\Emargement;
use App\Reservation\Entity\FacturationNoShow;
use App\Reservation\Entity\IndisponibiliteRessource;
use App\Reservation\Entity\ListeAttente;
use App\Reservation\Entity\ParticipantReservation;
use App\Reservation\Entity\ProjectionAccesReservation;
use App\Reservation\Entity\RegleAnnulation;
use App\Reservation\Entity\Recurrence;
use App\Reservation\Entity\Reservation;
use App\Reservation\Entity\Ressource;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Cloisonnement multi-entités des ressources M5 (RG-SOCLE-05, patron `PerimetreVenteExtension`) : un
 * utilisateur ne voit que les objets rattachés à un établissement où il possède au moins une
 * affectation.
 */
final class PerimetreReservationExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    /** @var array<class-string, string> */
    private const CHEMINS = [
        Ressource::class => '{root}.etablissement',
        Activite::class => '{root}.etablissement',
        Creneau::class => '{root}.etablissement',
        Reservation::class => '{root}.etablissement',
        RegleAnnulation::class => '{root}.etablissement',
        ProjectionAccesReservation::class => '{root}.etablissement',
        DisponibiliteRessource::class => 'ress.etablissement',
        IndisponibiliteRessource::class => 'ress.etablissement',
        ListeAttente::class => 'cr.etablissement',
        ParticipantReservation::class => 'res.etablissement',

        // Ajoutee le 28/08 : elle portait un `etablissement` et rien ne s'en servait. Une regle de
        // recurrence dit les horaires et le rythme d'exploitation d'un site.
        Recurrence::class => '{root}.etablissement',
        Emargement::class => 'res.etablissement',
        FacturationNoShow::class => 'res.etablissement',
    ];

    public function __construct(
        private readonly Security $security,
        private readonly ContexteEtablissement $contexte,
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
        if (!isset(self::CHEMINS[$resourceClass])) {
            return;
        }
        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            return;
        }

        $rootAlias = $queryBuilder->getRootAliases()[0];

        if (\in_array($resourceClass, [DisponibiliteRessource::class, IndisponibiliteRessource::class], true)) {
            // Ces deux-la ne portent pas d'etablissement : elles le tiennent de leur ressource.
            // Sans cette jointure, leurs collections etaient lisibles d'un etablissement a l'autre —
            // on voyait les plages d'ouverture et les fermetures exceptionnelles des concurrents.
            $queryBuilder->innerJoin($rootAlias . '.ressource', 'ress');
        } elseif ($resourceClass === ListeAttente::class) {
            $queryBuilder->innerJoin($rootAlias . '.creneau', 'cr');
        } elseif (\in_array($resourceClass, [ParticipantReservation::class, Emargement::class, FacturationNoShow::class], true)) {
            $queryBuilder->innerJoin($rootAlias . '.reservation', 'res');
        }

        $chemin = str_replace('{root}', $rootAlias, self::CHEMINS[$resourceClass]);

        // ── L'AXE EST L'ÉTABLISSEMENT ACTIF ──────────────────────────────────────────────────
        //
        // Bascule du 28/08. Le filtre portait sur le PÉRIMÈTRE du lecteur : un exploitant affecté à
        // trois sites voyait les données des trois, sous le titre d'un seul. Constaté dans le
        // navigateur — le tableau de bord d'un site créé le matin même annonçait une session de
        // caisse ouverte, celle du voisin, et la pastille « prêt à vendre » s'allumait sur un site
        // sans caisse.
        //
        // L'écran porte un sélecteur d'établissement et titre ses pages du site actif : les données
        // le suivent. Le périmètre dit ce qu'on a le DROIT de voir ; l'actif dit ce qu'on REGARDE.
        // `PermissionVoter` a déjà refusé un établissement hors périmètre avant cette requête : on
        // filtre, on ne rejuge pas.
        $actif = $this->contexte->idActif();
        if ($actif === null) {
            // Fermeture par défaut : une liste vide se remarque, une liste inter-établissements a
            // seulement l'air plus longue.
            $queryBuilder->andWhere('1 = 0');

            return;
        }

        $queryBuilder
            ->andWhere(sprintf('IDENTITY(%s) = :%s', $chemin, 'perimetre_reservation_actif'))
            ->setParameter('perimetre_reservation_actif', $actif, 'uuid')
            ->distinct();
    }
}
