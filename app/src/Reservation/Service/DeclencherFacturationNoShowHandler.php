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

        $retenu = (float) $reservation->getVersementRetenuMontant();
        $nature = $creneau?->getActivite()?->getNatureVersement();

        // ARRHES : LE DEDIT EST LA COMPENSATION, ON NE RECLAME RIEN DE PLUS.
        //
        // `RegleAnnulation` calcule deja une indemnite. Laisser les deux mecanismes s'appliquer
        // ferait payer au client son absence DEUX FOIS -- une fois en perdant ses arrhes, une fois
        // par cette facturation. Personne ne l'aurait vu avant une reclamation.
        //
        // Et la condition porte sur ce qui a ETE RETENU, pas sur la nature declaree : un montant
        // annonce mais jamais encaisse n'engage aucun regime, sinon une prestation mal configuree
        // offrirait l'absence a tous ses clients.
        if ($nature !== null && $nature->eteintLaCreance() && $retenu > 0.0) {
            $this->em->flush();

            return null;
        }

        $duTotal = (float) $regle->montantCalcule($creneau?->tarifReference() ?? '0.00');

        // ACOMPTE : le versement s'impute sur ce qui reste du. Jamais en dessous de zero -- un
        // acompte superieur a l'indemnite ne cree pas une dette de l'etablissement envers le client.
        $solde = max(0.0, $duTotal - $retenu);

        $facturation = new FacturationNoShow();
        $facturation->setReservation($reservation)
            ->setRegleAppliquee($regle)
            ->setMontant(number_format($solde, 2, '.', ''))
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
