<?php

declare(strict_types=1);

namespace App\Reporting\Projection\Doctrine;

use App\Reporting\Projection\ProjectionReservationInterface;
use App\Reporting\ValueObject\Periode;
use App\Reservation\Entity\Creneau;
use App\Reservation\Entity\Reservation;
use App\Reservation\Enum\StatutReservation;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Adaptateur Doctrine par défaut de `ProjectionReservationInterface` (§2.1 plan-reporting.md). Lit
 * `Creneau`/`Reservation` (M5) — jamais d'écriture.
 */
final class ProjectionReservationDoctrine implements ProjectionReservationInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function tauxRemplissage(Uuid $etablissementId, Periode $periode): string
    {
        /** @var list<Creneau> $creneaux */
        $creneaux = $this->em->createQueryBuilder()
            ->select('c')
            ->from(Creneau::class, 'c')
            ->where('c.etablissement = :etablissement')
            ->andWhere('c.debut >= :debut')
            ->andWhere('c.debut <= :fin')
            ->setParameter('etablissement', $etablissementId, 'uuid')
            ->setParameter('debut', $periode->debut)
            ->setParameter('fin', $periode->fin)
            ->getQuery()
            ->getResult();

        if ($creneaux === []) {
            return '0.00';
        }

        $capaciteTotale = 0;
        $occupationTotale = 0;
        foreach ($creneaux as $creneau) {
            $capaciteTotale += $creneau->getCapacite();
            $occupationTotale += (int) $this->em->createQueryBuilder()
                ->select('COUNT(r.id)')
                ->from(Reservation::class, 'r')
                ->where('r.creneau = :creneau')
                ->andWhere('r.statut IN (:statutsOccupants)')
                ->setParameter('creneau', $creneau->getId(), 'uuid')
                ->setParameter('statutsOccupants', [StatutReservation::Confirmee, StatutReservation::Honoree])
                ->getQuery()
                ->getSingleScalarResult();
        }

        if ($capaciteTotale === 0) {
            return '0.00';
        }

        return number_format(($occupationTotale / $capaciteTotale) * 100, 2, '.', '');
    }

    public function noShow(Uuid $etablissementId, Periode $periode): int
    {
        $resultat = $this->em->createQueryBuilder()
            ->select('COUNT(r.id)')
            ->from(Reservation::class, 'r')
            ->innerJoin('r.creneau', 'c')
            ->where('r.etablissement = :etablissement')
            ->andWhere('r.statut = :noShow')
            ->andWhere('c.debut >= :debut')
            ->andWhere('c.debut <= :fin')
            ->setParameter('etablissement', $etablissementId, 'uuid')
            ->setParameter('noShow', StatutReservation::NoShowFacture)
            ->setParameter('debut', $periode->debut)
            ->setParameter('fin', $periode->fin)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $resultat;
    }
}
