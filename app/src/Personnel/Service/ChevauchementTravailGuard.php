<?php

declare(strict_types=1);

namespace App\Personnel\Service;

use App\Personnel\Entity\AffectationTravail;
use App\Personnel\Entity\Employe;
use App\Personnel\Enum\StatutAffectationTravail;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Bloque un conflit d'affectation : un même Employé ne peut être affecté à deux CreneauTravail
 * chevauchants, y compris entre établissements différents (RG-PERSO-04, CA-4, généralisation
 * `App\Reservation\Service\ChevauchementCreneauGuard`/RG-M5-03 à l'humain plutôt qu'à une ressource).
 */
final class ChevauchementTravailGuard
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** Vrai s'il existe une affectation non annulée de l'employé sur un créneau dont la fenêtre chevauche celle donnée. */
    public function enConflit(Employe $employe, \DateTimeImmutable $debut, \DateTimeImmutable $fin, ?Uuid $exclureAffectation = null): bool
    {
        $qb = $this->em->getRepository(AffectationTravail::class)->createQueryBuilder('a')
            ->innerJoin('a.creneauTravail', 'c')
            ->andWhere('a.employe = :employe')
            ->andWhere('a.statut != :annulee')
            ->andWhere('c.debut < :fin')
            ->andWhere('c.fin > :debut')
            ->setParameter('employe', $employe->getId(), 'uuid')
            ->setParameter('annulee', StatutAffectationTravail::Annulee->value)
            ->setParameter('debut', $debut, 'datetime_immutable')
            ->setParameter('fin', $fin, 'datetime_immutable');

        if ($exclureAffectation !== null) {
            $qb->andWhere('a.id != :exclu')->setParameter('exclu', $exclureAffectation, 'uuid');
        }

        $qb->setMaxResults(1);

        return $qb->getQuery()->getOneOrNullResult() !== null;
    }
}
