<?php

declare(strict_types=1);

namespace App\Reservation\Service;

use App\Reservation\Entity\Creneau;
use App\Reservation\Entity\Ressource;
use App\Reservation\Enum\StatutCreneau;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Bloque un conflit de ressource : même Ressource déjà affectée sur un Créneau chevauchant
 * (RG-M5-03, CA-2). Deux fenêtres [d1,f1) et [d2,f2) chevauchent si d1 < f2 ET d2 < f1.
 */
final class ChevauchementCreneauGuard
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** Vrai s'il existe un créneau non annulé sur la même ressource dont la fenêtre chevauche celle donnée. */
    public function enConflit(Ressource $ressource, \DateTimeImmutable $debut, \DateTimeImmutable $fin, ?Uuid $exclureCreneau = null): bool
    {
        $qb = $this->em->getRepository(Creneau::class)->createQueryBuilder('c')
            ->andWhere('c.ressource = :ressource')
            ->andWhere('c.statut != :annule')
            ->andWhere('c.debut < :fin')
            ->andWhere('c.fin > :debut')
            ->setParameter('ressource', $ressource->getId(), 'uuid')
            ->setParameter('annule', StatutCreneau::Annule->value)
            ->setParameter('debut', $debut, 'datetime_immutable')
            ->setParameter('fin', $fin, 'datetime_immutable');

        if ($exclureCreneau !== null) {
            $qb->andWhere('c.id != :exclu')->setParameter('exclu', $exclureCreneau, 'uuid');
        }

        $qb->setMaxResults(1);

        return $qb->getQuery()->getOneOrNullResult() !== null;
    }
}
