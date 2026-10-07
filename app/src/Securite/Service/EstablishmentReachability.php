<?php

declare(strict_types=1);

namespace App\Securite\Service;

use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\DelegationDroit;
use App\Securite\Entity\Utilisateur;
use App\Securite\Enum\StatutDelegation;
use App\Securite\Port\SupportAccessScopeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * « CET UTILISATEUR PEUT-IL SEULEMENT NOMMER CET ÉTABLISSEMENT ? » — la question, posée une fois.
 *
 * La réponse existait déjà, éparpillée : `PerimetreEtablissementExtension` construit la liste du
 * sélecteur (affectation OU accès d'assistance), `CalculateurDroits` retient en plus les délégations
 * actives. Mais rien ne la posait sur un établissement DÉSIGNÉ — par l'en-tête `X-Etablissement`, ou
 * par un identifiant dans le corps d'une requête. Audit du 06/09, constats 3 et 5 : un inconnu affecté
 * nulle part a écrit un événement d'agenda chez Patinoire B en nommant son identifiant dans l'en-tête ;
 * onze processeurs résolvaient un établissement du corps par `find()` sans le confronter à personne.
 *
 * ── LES TROIS PORTES, ET POURQUOI LES TROIS ────────────────────────────────────────────────────
 *
 *  - une AFFECTATION sur l'établissement — la porte ordinaire ;
 *  - une DÉLÉGATION active sur l'établissement — `CalculateurDroits` en tire des droits, donc un
 *    délégué qui pose l'en-tête de ce site agit légitimement ; refuser ici ce que le voter accorde
 *    casserait toute délégation (`DelegationTest`) ;
 *  - un ACCÈS D'ASSISTANCE utilisable à cet instant — nominatif, motivé, borné dans le temps ; c'est
 *    ce qui rend un établissement client atteignable par un agent de l'éditeur sans affectation
 *    permanente (RG-ED-07).
 *
 * ⚠ CE SERVICE NE DIT PAS « QUELS DROITS » — c'est `CalculateurDroits`. Il dit « atteignable ». Les deux
 * questions sont distinctes, et les garder séparées est ce qui permet à un accès d'assistance de rendre
 * un établissement visible sans y accorder tous les gestes.
 *
 * ⚠ L'INSTANT EST UN ARGUMENT, pour la raison écrite sur `SupportAccessScopeInterface` : une
 * implémentation qui lirait l'horloge ne se testerait qu'à l'instant qu'il est.
 */
final class EstablishmentReachability
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SupportAccessScopeInterface $supportScope,
        private readonly PlatformScope $platformScope,
    ) {
    }

    public function canReach(Utilisateur $user, Uuid $establishmentId, \DateTimeImmutable $at): bool
    {
        // L'entité d'abord : les critères `findBy` sur une association attendent l'entité, et un
        // identifiant inconnu doit rendre « non » sans requête supplémentaire.
        $establishment = $this->em->getRepository(Etablissement::class)->find($establishmentId);
        if (!$establishment instanceof Etablissement) {
            return false;
        }

        return $this->canReachEstablishment($user, $establishment, $at);
    }

    public function canReachEstablishment(Utilisateur $user, Etablissement $establishment, \DateTimeImmutable $at): bool
    {
        // ÉQUIPE PLATEFORME (mono-propriétaire) : atteint n'importe quel établissement (RG-ED-07,
        // exception assumée — voir `PlatformScope`). L'établissement est déjà résolu et existe ici.
        if ($this->platformScope->isGlobal($user)) {
            return true;
        }

        $affectation = $this->em->getRepository(Affectation::class)->findOneBy([
            'utilisateur' => $user,
            'etablissement' => $establishment,
        ]);
        if ($affectation instanceof Affectation) {
            return true;
        }

        /** @var list<DelegationDroit> $delegations */
        $delegations = $this->em->getRepository(DelegationDroit::class)->findBy([
            'beneficiaire' => $user,
            'etablissement' => $establishment,
            'statut' => StatutDelegation::Active,
        ]);
        foreach ($delegations as $delegation) {
            // Même double garde que `CalculateurDroits` : le statut est tenu par une tâche planifiée
            // qui peut être en retard, la date fait foi.
            if ($delegation->estActiveMaintenant($at)) {
                return true;
            }
        }

        $wanted = $establishment->getId();
        foreach ($this->supportScope->reachableEstablishmentIds($user, $at) as $reachable) {
            if ($reachable->equals($wanted)) {
                return true;
            }
        }

        return false;
    }
}
