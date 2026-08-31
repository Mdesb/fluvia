<?php

declare(strict_types=1);

namespace App\Recouvrement\Service;

use App\Organisation\Entity\Etablissement;
use App\Recouvrement\Entity\IncidentImpaye;
use App\Recouvrement\Entity\PolitiqueRecouvrement;
use App\Recouvrement\Entity\RepresentationSepa;
use App\Recouvrement\Enum\MomentRefusAcces;
use App\Recouvrement\Enum\ResultatRepresentationSepa;
use App\Recouvrement\Enum\StatutIncidentImpaye;
use App\Recouvrement\Event\IncidentImpayeDetecteEvent;
use App\Recouvrement\Event\IncidentImpayeResoluEvent;
use App\Sepa\Entity\RejetSepa;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Machine anti-impayés — moteur générique partagé (extrait de
 * `App\Sport\Service\MoteurAntiImpayesHandler`), réutilisable par toute activité à abonnement (Sport,
 * Piscine en régie, futures). Pilotée par `PolitiqueRecouvrement.momentRefusAcces` : l'accès n'est
 * refusé qu'aux points où le paramètre l'exige — la simple création d'un `IncidentImpaye` ne propage
 * rien par défaut (politique par défaut `apres_representation_echouee`). Aucune référence à une
 * verticale (fitness ou autre) : le contrat en cause est désigné par `typeRedevable`/`referenceRedevable`
 * et résolu via `RedevableRegistry` ; les conséquences métier propres à la verticale (statut du contrat,
 * comptabilité) sont notifiées par événements (`IncidentImpayeDetecteEvent`/`IncidentImpayeResoluEvent`).
 */
final class MoteurRecouvrementHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PropagationAccesHandler $propagation,
        private readonly EventDispatcherInterface $dispatcher,
    ) {
    }

    public function detecterRejet(
        Etablissement $etablissement,
        string $typeRedevable,
        string $referenceRedevable,
        int $montantCentimes,
        \DateTimeImmutable $dateRejet,
        string $codeRetour,
        ?string $libelleRetour = null,
        ?string $referenceEcheanceOrigine = null,
        ?RejetSepa $rejetSepa = null,
    ): IncidentImpaye {
        $incident = new IncidentImpaye();
        $incident->setEtablissement($etablissement)
            ->setTypeRedevable($typeRedevable)
            ->setReferenceRedevable($referenceRedevable)
            ->setReferenceEcheanceOrigine($referenceEcheanceOrigine)
            ->setRejetOrigine($rejetSepa)
            ->setMontantCentimes($montantCentimes)
            ->setDateRejet($dateRejet)
            ->setMotifBancaire($codeRetour)
            ->setLibelleMotifBancaire($libelleRetour)
            ->setStatut(StatutIncidentImpaye::Representation);
        $this->em->persist($incident);

        $politique = $this->politiquePour($etablissement);

        if ($politique->getNbRepresentationsMax() > 0) {
            $delai = $politique->getCalendrierRepresentationJours()[0] ?? 5;
            $representation = new RepresentationSepa();
            $representation->setIncident($incident)->setDateProgrammee($dateRejet->modify(sprintf('+%d days', $delai)));
            $this->em->persist($representation);
        } else {
            // Aucune représentation configurée : bascule direct en recouvrement.
            $incident->setStatut(StatutIncidentImpaye::Recouvrement);
        }

        $this->em->flush();

        // Notifie la verticale AVANT toute décision de blocage d'accès (elle bascule son propre statut
        // métier, ex. `AbonnementFitness.statut = Impaye`, quel que soit le moment du refus d'accès).
        $this->dispatcher->dispatch(new IncidentImpayeDetecteEvent($incident, $montantCentimes, $dateRejet));

        // Refus d'accès éventuel AVANT tout résultat de représentation (`apres_1er_echec`), ou si 0
        // représentation configurée (rien à attendre).
        if ($politique->getMomentRefusAcces() === MomentRefusAcces::Apres1erEchec || $politique->getNbRepresentationsMax() === 0) {
            $incident->setAccesBloque(true);
            $this->em->flush();
            $this->propagation->desactiver($typeRedevable, $referenceRedevable);
        }

        return $incident;
    }

    public function enregistrerResultatRepresentation(RepresentationSepa $representation, ResultatRepresentationSepa $resultat): void
    {
        $representation->setDateExecution(new \DateTimeImmutable())->setResultat($resultat);
        // Flush immédiat : `compterEchouees()` interroge la base plus bas, elle doit refléter ce résultat.
        $this->em->flush();
        $incident = $representation->getIncident();
        \assert($incident instanceof IncidentImpaye);

        if ($resultat === ResultatRepresentationSepa::Reussie) {
            // Cas limite : une représentation réussie restaure l'accès comme une résolution 1 clic
            // (même garantie, quel que soit le canal).
            $incident->setStatut(StatutIncidentImpaye::Resolu)
                ->setAccesBloque(false)
                ->setDateResolution(new \DateTimeImmutable());
            $this->em->flush();

            // ⚠ RÉÉVALUER, PAS ACTIVER : un autre impayé du même client peut encore bloquer.
            $this->propagation->reevaluer($incident->getTypeRedevable(), $incident->getReferenceRedevable());
            $this->dispatcher->dispatch(new IncidentImpayeResoluEvent($incident, $incident->getMontantCentimes(), new \DateTimeImmutable(), 'representation_reussie'));

            return;
        }

        $etablissement = $incident->getEtablissement();
        \assert($etablissement instanceof Etablissement);
        $politique = $this->politiquePour($etablissement);
        $nbEchouees = $this->compterEchouees($incident);
        $calendrier = $politique->getCalendrierRepresentationJours();

        if ($nbEchouees < $politique->getNbRepresentationsMax()) {
            $delai = $calendrier[$nbEchouees] ?? ($calendrier[array_key_last($calendrier)] ?? 5);
            $suivante = new RepresentationSepa();
            $suivante->setIncident($incident)->setDateProgrammee((new \DateTimeImmutable())->modify(sprintf('+%d days', $delai)));
            $this->em->persist($suivante);
        } else {
            $incident->setStatut(StatutIncidentImpaye::Recouvrement);
        }

        $doitRefuser = match ($politique->getMomentRefusAcces()) {
            MomentRefusAcces::Apres1erEchec => true,
            MomentRefusAcces::ApresRepresentationEchouee => true,
            MomentRefusAcces::ApresNRepresentationsEchouees => $nbEchouees >= ($politique->getNReprAvantBlocage() ?? PHP_INT_MAX),
        };

        if ($doitRefuser) {
            $incident->setAccesBloque(true);
        }

        $this->em->flush();

        if ($doitRefuser) {
            $this->propagation->desactiver($incident->getTypeRedevable(), $incident->getReferenceRedevable());
        }
    }

    private function compterEchouees(IncidentImpaye $incident): int
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

    private function politiquePour(Etablissement $etablissement): PolitiqueRecouvrement
    {
        $politique = $this->em->getRepository(PolitiqueRecouvrement::class)->findOneBy(['etablissement' => $etablissement]);
        if ($politique instanceof PolitiqueRecouvrement) {
            return $politique;
        }

        // Valeur de repli conforme au défaut documenté si aucune politique paramétrée.
        $defaut = new PolitiqueRecouvrement();
        $defaut->setEtablissement($etablissement);

        return $defaut;
    }
}
