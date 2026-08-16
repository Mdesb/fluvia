<?php

declare(strict_types=1);

namespace App\Padel\Service;

use App\Padel\Entity\ReservationPadel;
use App\Padel\Enum\StatutPartieOuverte;
use App\Reservation\Enum\StatutPaiementParticipant;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Répartit le surcoût d'une partie ouverte « maintenue à 3 » entre les 3 joueurs présents (décision
 * actée « Partie ouverte 3/4 », RG-PADEL-03, CA-4). Le prix du créneau (`Reservation.montantDu`) est
 * conçu pour 4 parts égales (§4.3 du plan) : si un 4ᵉ joueur manque, sa part est répartie équitablement
 * entre les 3 présents (seul mode livré, `ModeRepartitionSurcout::EquitablePresents`, décision n°4).
 */
final class RepartitionSurcoutHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** Vrai si le traitement « maintenue à 3 » a été appliqué. */
    public function appliquerSiApplicable(ReservationPadel $reservationPadel): bool
    {
        if (!$reservationPadel->isOuverte() || $reservationPadel->getStatutPartie() !== StatutPartieOuverte::Ouverte) {
            return false;
        }

        $reservation = $reservationPadel->getReservation();
        if ($reservation === null) {
            return false;
        }

        $participants = $reservation->getParticipants();
        if ($participants->count() !== 3) {
            return false; // seul le seuil 3/4 est tranché par la décision actée (§4.3, cas < 3 hors périmètre).
        }

        $montantDu = (float) $reservation->getMontantDu();
        $partManquante = $montantDu / 4;
        $supplementParPersonne = $partManquante / 3;

        foreach ($participants as $participant) {
            $nouvellePart = (float) $participant->getPartMontant() + $supplementParPersonne;
            $participant->setPartMontant(number_format($nouvellePart, 2, '.', ''));
            $participant->setStatutPaiement(StatutPaiementParticipant::Paye);
        }

        $reservationPadel->setStatutPartie(StatutPartieOuverte::MaintenueA3);
        $this->em->flush();

        return true;
    }
}
