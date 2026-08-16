<?php

declare(strict_types=1);

namespace App\Reservation\Service;

use App\Reservation\Entity\ProjectionAccesReservation;
use App\Reservation\Entity\Reservation;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Projette un droit d'accès optionnel sur la fenêtre du créneau (RG-M5-12, CA-15), déclenché à la
 * confirmation d'une réservation dont la Ressource porte `ouvreAcces=true`. `droitAccesRef` reste
 * **nullable** : la projection réelle vers `App\Acces\Entity\DroitAcces` nécessite une extension du
 * port `App\Acces\Port\ProjectionDroitInterface` côté L3 (nouveau cas `TypeDroitAcces::Reservation`),
 * **hors périmètre de ce lot** (Risque n°2 du plan) — no-op documenté (log) en attendant.
 */
final class ProjectionAccesReservationHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function projeterSiApplicable(Reservation $reservation): ?ProjectionAccesReservation
    {
        $creneau = $reservation->getCreneau();
        $ressource = $creneau?->getRessource();
        if ($creneau === null || $ressource === null || !$ressource->isOuvreAcces()) {
            return null;
        }

        $projection = new ProjectionAccesReservation();
        $projection->setReservation($reservation)
            ->setFenetreDebut($creneau->getDebut())
            ->setFenetreFin($creneau->getFin())
            ->setEtablissement($reservation->getEtablissement());
        // droitAccesRef volontairement laissé null (no-op documenté, Risque n°2 du plan).
        $this->em->persist($projection);
        $this->em->flush();

        $this->logger->info('reservation.projection_acces.no_op', [
            'reservation' => (string) $reservation->getId(),
            'motif' => 'ProjectionDroitInterface (L3) non étendu pour App\\Reservation dans ce lot (Risque n°2 du plan).',
        ]);

        return $projection;
    }
}
