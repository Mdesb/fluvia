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
 */
final class DeclencherFacturationNoShowHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ResolveurRegleAnnulation $resolveur,
        private readonly ResolveurStrategieFacturation $strategies,
    ) {
    }

    public function declencher(Reservation $reservation, StatutReservation $statutCible): ?FacturationNoShow
    {
        $creneau = $reservation->getCreneau();
        $regle = $creneau !== null ? $this->resolveur->resoudre($creneau) : null;

        $reservation->setStatut($statutCible);

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
