<?php

declare(strict_types=1);

namespace App\Reservation\Service;

use App\Reservation\Entity\Creneau;
use App\Reservation\Entity\Reservation;
use App\Reservation\Enum\StatutReservation;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Jauge d'un Créneau (RG-M5-01) : le nombre de réservations occupant une place (confirmée/honorée)
 * ne peut dépasser sa capacité (CA-3/CA-4).
 */
final class JaugeCreneauGuard
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function placesOccupees(Creneau $creneau): int
    {
        return (int) $this->em->getRepository(Reservation::class)->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->andWhere('r.creneau = :creneau')
            ->andWhere('r.statut IN (:statuts)')
            ->setParameter('creneau', $creneau->getId(), 'uuid')
            ->setParameter('statuts', [StatutReservation::Confirmee->value, StatutReservation::Honoree->value])
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function estComplet(Creneau $creneau): bool
    {
        return $this->placesOccupees($creneau) >= $creneau->getCapacite();
    }

    public function placesRestantes(Creneau $creneau): int
    {
        return max(0, $creneau->getCapacite() - $this->placesOccupees($creneau));
    }
}
