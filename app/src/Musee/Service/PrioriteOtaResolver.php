<?php

declare(strict_types=1);

namespace App\Musee\Service;

use App\Musee\Entity\ReservationOTA;
use App\Musee\Enum\StatutReservationOTA;
use App\Reservation\Enum\StatutReservation;
use App\Reservation\Service\JaugeRessourceMereHandler;
use App\Reservation\Service\ProjectionAccesReservationHandler;
use App\Reservation\Service\StockCardCreditHandler;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Priorité au 1ᵉʳ billet confirmé en cas de sur-vente OTA (décision actée, US-MUSEE-08, CA-8) :
 * horodatage serveur faisant foi (comparable à une clé d'idempotence, cf. `Passage.idempotenceClé`
 * en L3 pour l'esprit de la mécanique). Le(s) billet(s) en conflit sont refusés côté OTA (le
 * partenaire gère le remboursement selon ses CGV, hors périmètre applicatif — ⚠ pas de rebascule
 * automatique sur un autre créneau, non tranché par la décision actée).
 *
 * **Le perdant est annulé par la plateforme, donc la plateforme lui doit ce qu'elle lui a pris.**
 * L'arbitrage n'est pas une annulation du client : celui-ci n'a rien fait de mal, et son billet
 * disparaît par une décision qui lui est extérieure. Trois restitutions en découlent, alignées sur
 * `AnnulerReservationProcessor` dans sa branche « dans les délais » :
 *
 * 1. **le crédit de carte** (CQ-3/CQ-6) — sans quoi l'arbitrage vole une séance au porteur d'une
 *    carte de N réservations, définitivement et sans trace ;
 * 2. **le droit d'accès projeté** — un billet annulé qui ouvre encore un tourniquet est un défaut de
 *    contrôle d'accès, pas une imprécision comptable ;
 * 3. **la jauge de la ressource mère** — sinon la place libérée reste comptée occupée et le musée
 *    refuse un visiteur pour un créneau qui n'est plus vendu.
 *
 * **Ce qui reste hors périmètre, et le demeure** : le remboursement monétaire. Il appartient à l'OTA
 * selon ses CGV, comme dit plus haut — on ne rembourse pas l'argent d'un partenaire à sa place.
 */
final class PrioriteOtaResolver
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly StockCardCreditHandler $carteStock,
        private readonly ProjectionAccesReservationHandler $projectionAcces,
        private readonly JaugeRessourceMereHandler $jaugeMere,
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
                $this->em->flush();

                $this->projectionAcces->revoquerSiProjete($reservation);
                $this->carteStock->restituer($reservation->getCreditDroitRef());

                $ressource = $reservation->getCreneau()?->getRessource();
                if ($ressource !== null) {
                    // ACT-1 : on rend exactement ce qui avait été pris, pas une unité.
                    $this->jaugeMere->decrementer($ressource, $reservation->getQuantity());
                }
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
