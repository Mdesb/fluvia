<?php

declare(strict_types=1);

namespace App\Personnel\Service;

use App\Personnel\Entity\Absence;
use App\Personnel\Entity\Employe;
use App\Personnel\Enum\StatutAbsence;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Une Absence **validée** bloque toute nouvelle affectation de l'employé sur sa période
 * (RG-PERSO-05, CA-7).
 */
final class AbsenceConflitGuard
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function estAbsentValide(Employe $employe, \DateTimeImmutable $debut, \DateTimeImmutable $fin): bool
    {
        $absence = $this->em->getRepository(Absence::class)->createQueryBuilder('a')
            ->andWhere('a.employe = :employe')
            ->andWhere('a.statut = :validee')
            ->andWhere('a.debut < :fin')
            ->andWhere('a.fin > :debut')
            ->setParameter('employe', $employe->getId(), 'uuid')
            ->setParameter('validee', StatutAbsence::Validee->value)
            ->setParameter('debut', $debut, 'datetime_immutable')
            ->setParameter('fin', $fin, 'datetime_immutable')
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();

        return $absence instanceof Absence;
    }
}
