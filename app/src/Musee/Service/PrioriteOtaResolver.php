<?php

declare(strict_types=1);

namespace App\Musee\Service;

use App\Musee\Entity\ReservationOTA;
use App\Musee\Enum\StatutReservationOTA;
use App\Reservation\Enum\StatutReservation;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Priorité au 1ᵉʳ billet confirmé en cas de sur-vente OTA (décision actée, US-MUSEE-08, CA-8) :
 * horodatage serveur faisant foi (comparable à une clé d'idempotence, cf. `Passage.idempotenceClé`
 * en L3 pour l'esprit de la mécanique). Le(s) billet(s) en conflit sont refusés côté OTA (le
 * partenaire gère le remboursement selon ses CGV, hors périmètre applicatif — ⚠ pas de rebascule
 * automatique sur un autre créneau, non tranché par la décision actée).
 */
final class PrioriteOtaResolver
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @param list<ReservationOTA> $candidats Réservations OTA en conflit sur la même place
     *
     * @return list<ReservationOTA> même liste, triée, avec `statutOta` mis à jour
     */
    public function arbitrer(array $candidats): array
    {
        if (\count($candidats) < 2) {
            return $candidats;
        }

        usort($candidats, static fn (ReservationOTA $a, ReservationOTA $b): int => $a->getHorodatageConfirmation() <=> $b->getHorodatageConfirmation());

        $gagnant = $candidats[0];
        $gagnant->setStatutOta(StatutReservationOTA::Confirmee);

        foreach (\array_slice($candidats, 1) as $perdant) {
            $perdant->setStatutOta(StatutReservationOTA::RefuseeConflit);

            $reservation = $perdant->getReservationRattachee();
            if ($reservation !== null && $reservation->getStatut() === StatutReservation::Confirmee) {
                $reservation->setStatut(StatutReservation::AnnuleeLibre);
            }

            $allocation = $perdant->getAllocation();
            if ($allocation !== null) {
                $allocation->setQuotaConsomme(max(0, $allocation->getQuotaConsomme() - 1));
            }
        }

        $this->em->flush();

        return $candidats;
    }
}
