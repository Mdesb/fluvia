<?php

declare(strict_types=1);

namespace App\Reservation\Service;

use App\Reservation\Entity\FacturationNoShow;
use App\Reservation\Entity\Reservation;
use App\Reservation\Enum\ModeFacturationNoShow;
use App\Reservation\Enum\StatutFacturationNoShow;
use App\Reservation\Enum\StatutReservation;
use App\Reservation\Facturation\ResolveurStrategieFacturation;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Bascule une Réservation en annulée tardive facturée / no-show, résout la `RegleAnnulation`
 * applicable et crée la `FacturationNoShow` correspondante (RG-M5-09, CA-9/CA-11). Si aucune règle
 * active ne couvre le créneau, aucune `FacturationNoShow` n'est créée (§7 cas limite : comportement
 * historique, place/quota perdu sans facturation). Pour les modes réellement branchés qui ne
 * nécessitent aucun agent (`debit_pmv`), l'application de la stratégie est tentée automatiquement.
 *
 * Point de passage partagé (RG-ACC3-05, plan-acc3.md §5.3) : couvre à la fois la branche tardive de
 * `AnnulerReservationProcessor` et la branche no-show de `BasculerNoShowCommand` — la révocation du
 * `DroitAcces` éventuellement projeté est câblée ici une seule fois pour les deux appelants.
 */
final class DeclencherFacturationNoShowHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ResolveurRegleAnnulation $resolveur,
        private readonly ResolveurStrategieFacturation $strategies,
        private readonly ProjectionAccesReservationHandler $projectionAcces,
        private readonly ApplyNoShowCreditIssueHandler $applyCreditIssue,
    ) {
    }

    public function declencher(Reservation $reservation, StatutReservation $statutCible): ?FacturationNoShow
    {
        $creneau = $reservation->getCreneau();
        $regle = $creneau !== null ? $this->resolveur->resoudre($creneau) : null;

        $reservation->setStatut($statutCible);
        $this->projectionAcces->revoquerSiProjete($reservation);

        if ($regle === null) {
            $this->em->flush();

            return null;
        }

        $facturation = new FacturationNoShow();
        $facturation->setReservation($reservation)
            ->setRegleAppliquee($regle)
            ->setMontant($regle->montantCalcule($creneau?->tarifReference() ?? '0.00'))
            ->setStatut(StatutFacturationNoShow::AFacturer);
        $this->em->persist($facturation);
        // RG-CQ5-07 — POINT D'IDEMPOTENCE (plan-cq5.md §3.6) : ce flush() exécute l'INSERT et fait
        // respecter uniq_facturation_no_show_reservation. Un second appel pour la même réservation
        // échoue ICI (avant toute écriture de crédit) et propage l'exception —
        // ApplyNoShowCreditIssueHandler::apply() n'est alors jamais atteint une seconde fois (CA-8).
        // NE JAMAIS fusionner ce flush() avec celui qui suit (risque n°6 du plan).
        $this->em->flush();

        // RG-CQ5-03/04/05 — appliqué seulement APRÈS la création réussie de la FacturationNoShow.
        $resultat = $this->applyCreditIssue->apply($reservation, $regle->getIssueCreditNoShow());
        $facturation->setIssueCreditNoShow($regle->getIssueCreditNoShow())
            ->setCreditActionne($resultat->creditActioned)
            ->setCreditRestitue($resultat->creditRestored)
            ->setDroitAccesRestitueRef($resultat->droitId); // transient, RG-CQ5-08 (payload booking.reschedule_requested)
        $this->em->flush();

        // Modes sans agent (débit automatique) : tentative immédiate. `vente_differee_agent` et les
        // squelettes (`prelevement_differe`/`facture_a_encaisser`) restent en attente d'action.
        if ($regle->getModeFacturation() === ModeFacturationNoShow::DebitPmv) {
            $strategie = $this->strategies->pour(ModeFacturationNoShow::DebitPmv->value);
            $strategie?->appliquer($facturation, null);
        }

        return $facturation;
    }
}
