<?php

declare(strict_types=1);

namespace App\Sport\Service;

use App\Sport\Compta\Port\ProjectionEcritureSepaInterface;
use App\Sport\Entity\AbonnementFitness;
use App\Sport\Entity\EcheanceSepa;
use App\Sport\Entity\IncidentPrelevement;
use App\Sport\Entity\PolitiqueAntiImpayes;
use App\Sport\Entity\RejetPrelevement;
use App\Sport\Entity\RepresentationSepa;
use App\Sport\Enum\CanalResolutionImpaye;
use App\Sport\Enum\MomentRefusBadge;
use App\Sport\Enum\MotifInactiviteAccesFitness;
use App\Sport\Enum\ResultatRepresentationSepa;
use App\Sport\Enum\StatutAbonnementFitness;
use App\Sport\Enum\StatutEcheanceSepa;
use App\Sport\Enum\StatutIncidentPrelevement;
use App\Sport\Enum\StatutRejetPrelevement;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Machine anti-impayés (US-SPORT-05/06, RG-SPORT-01/02, décision actée §4.5, `critique`). Pilotée par
 * `PolitiqueAntiImpayes.momentRefusBadge` : le badge n'est refusé qu'aux points où le paramètre
 * l'exige (§0 point 3 du plan) — la simple création d'un `IncidentPrelevement` ne propage rien par
 * défaut (politique par défaut `apres_representation_echouee`, CA-5).
 */
final class MoteurAntiImpayesHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PropagationAccesFitnessHandler $propagation,
        private readonly ProjectionEcritureSepaInterface $projectionCompta,
    ) {
    }

    public function detecterRejet(EcheanceSepa $echeance, string $codeRetour, ?string $libelleRetour, \DateTimeImmutable $dateRejet): IncidentPrelevement
    {
        $abonnement = $echeance->getAbonnement();

        $rejet = new RejetPrelevement();
        $rejet->setEcheance($echeance)
            ->setCodeRetour($codeRetour)
            ->setLibelleRetour($libelleRetour)
            ->setDateReception(new \DateTimeImmutable())
            ->setMontantCentimes($echeance->getMontantCentimes())
            ->setStatut(StatutRejetPrelevement::Traite);
        $this->em->persist($rejet);

        $echeance->setStatut(StatutEcheanceSepa::Rejetee);

        $incident = new IncidentPrelevement();
        $incident->setAbonnement($abonnement)
            ->setEcheanceOrigine($echeance)
            ->setRejetOrigine($rejet)
            ->setMontantCentimes($echeance->getMontantCentimes())
            ->setDateRejet($dateRejet)
            ->setMotifBancaire($codeRetour)
            ->setStatut(StatutIncidentPrelevement::Representation);
        $this->em->persist($incident);

        $abonnement->setStatut(StatutAbonnementFitness::Impaye);

        $politique = $this->politiquePour($abonnement);

        if ($politique->getNbRepresentationsMax() > 0) {
            $delai = $politique->getCalendrierRepresentationJours()[0] ?? 5;
            $representation = new RepresentationSepa();
            $representation->setIncident($incident)->setDateProgrammee($dateRejet->modify(sprintf('+%d days', $delai)));
            $this->em->persist($representation);
        } else {
            // Aucune représentation configurée : bascule direct en recouvrement.
            $incident->setStatut(StatutIncidentPrelevement::Recouvrement);
        }

        $this->em->flush();

        // RG-SPORT-01/décision actée : refus de badge éventuel AVANT tout résultat de représentation
        // (CA-7, `apres_1er_echec`), ou si 0 représentation configurée (rien à attendre).
        if ($politique->getMomentRefusBadge() === MomentRefusBadge::Apres1erEchec || $politique->getNbRepresentationsMax() === 0) {
            $this->propagation->desactiver($abonnement, MotifInactiviteAccesFitness::Impaye);
        }

        $this->projectionCompta->enregistrerImpaye($abonnement->getEtablissement()->getId(), $abonnement->getId(), $echeance->getMontantCentimes(), $dateRejet);

        return $incident;
    }

    public function enregistrerResultatRepresentation(RepresentationSepa $representation, ResultatRepresentationSepa $resultat): void
    {
        $representation->setDateExecution(new \DateTimeImmutable())->setResultat($resultat);
        // Flush immédiat : `compterEchouees()` interroge la base plus bas, elle doit refléter ce résultat.
        $this->em->flush();
        $incident = $representation->getIncident();
        $abonnement = $incident->getAbonnement();

        if ($resultat === ResultatRepresentationSepa::Reussie) {
            // Cas limite §7 spec : une représentation réussie restaure l'accès comme une résolution 1
            // clic (même garantie RG-SPORT-03, quel que soit le canal).
            $incident->setStatut(StatutIncidentPrelevement::Resolu)
                ->setCanalResolution(CanalResolutionImpaye::Autre)
                ->setDateResolution(new \DateTimeImmutable());
            $abonnement->setStatut(StatutAbonnementFitness::Actif);
            $this->em->flush();

            $this->propagation->activer($abonnement);
            $this->projectionCompta->enregistrerEncaissement($abonnement->getEtablissement()->getId(), $abonnement->getId(), $incident->getMontantCentimes(), new \DateTimeImmutable(), 'representation_reussie');

            return;
        }

        $politique = $this->politiquePour($abonnement);
        $nbEchouees = $this->compterEchouees($incident);
        $calendrier = $politique->getCalendrierRepresentationJours();

        if ($nbEchouees < $politique->getNbRepresentationsMax()) {
            $delai = $calendrier[$nbEchouees] ?? ($calendrier[array_key_last($calendrier)] ?? 5);
            $suivante = new RepresentationSepa();
            $suivante->setIncident($incident)->setDateProgrammee((new \DateTimeImmutable())->modify(sprintf('+%d days', $delai)));
            $this->em->persist($suivante);
        } else {
            $incident->setStatut(StatutIncidentPrelevement::Recouvrement);
        }

        $doitRefuser = match ($politique->getMomentRefusBadge()) {
            MomentRefusBadge::Apres1erEchec => true,
            MomentRefusBadge::ApresRepresentationEchouee => true,
            MomentRefusBadge::ApresNRepresentationsEchouees => $nbEchouees >= ($politique->getNReprAvantBadge() ?? PHP_INT_MAX),
        };

        $this->em->flush();

        if ($doitRefuser) {
            $this->propagation->desactiver($abonnement, MotifInactiviteAccesFitness::Impaye);
        }
    }

    private function compterEchouees(IncidentPrelevement $incident): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(r.id)')
            ->from(RepresentationSepa::class, 'r')
            ->andWhere('IDENTITY(r.incident) = :incident')
            ->andWhere('r.resultat = :echouee')
            ->setParameter('incident', $incident->getId(), 'uuid')
            ->setParameter('echouee', ResultatRepresentationSepa::Echouee->value)
            ->getQuery()->getSingleScalarResult();
    }

    private function politiquePour(AbonnementFitness $abonnement): PolitiqueAntiImpayes
    {
        $politique = $this->em->getRepository(PolitiqueAntiImpayes::class)->findOneBy(['etablissement' => $abonnement->getEtablissement()]);
        if ($politique instanceof PolitiqueAntiImpayes) {
            return $politique;
        }

        // Valeur de repli conforme au défaut documenté (spec §4.5) si aucune politique paramétrée.
        $defaut = new PolitiqueAntiImpayes();
        $defaut->setEtablissement($abonnement->getEtablissement());

        return $defaut;
    }
}
