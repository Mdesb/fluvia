<?php

declare(strict_types=1);

namespace App\Musee\Service;

use App\Musee\Entity\Guide;
use App\Musee\Entity\VisiteGuidee;
use App\Musee\Enum\StatutVisiteGuidee;
use App\Reservation\Entity\Creneau;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Garde d'exclusivité guide (RG-MUS-02) : un guide ne peut être affecté à deux visites guidées dont
 * les créneaux se chevauchent (même patron que `AffectationLigneGuard`/`ChevauchementCreneauGuard`).
 */
final class GuideDisponibiliteGuard
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** Vrai si le guide a déjà une autre visite planifiée/confirmée dont le créneau chevauche celui donné. */
    public function enConflit(Guide $guide, \DateTimeImmutable $debut, \DateTimeImmutable $fin, ?Uuid $exclureVisite = null): bool
    {
        $qb = $this->em->getRepository(VisiteGuidee::class)->createQueryBuilder('v')
            ->innerJoin(Creneau::class, 'c', 'WITH', 'c = v.creneauVisite')
            ->andWhere('v.guide = :guide')
            ->andWhere('v.statut IN (:statuts)')
            ->andWhere('c.debut < :fin')
            ->andWhere('c.fin > :debut')
            ->setParameter('guide', $guide->getId(), 'uuid')
            ->setParameter('statuts', [StatutVisiteGuidee::Planifiee->value, StatutVisiteGuidee::Confirmee->value])
            ->setParameter('debut', $debut, 'datetime_immutable')
            ->setParameter('fin', $fin, 'datetime_immutable');

        if ($exclureVisite !== null) {
            $qb->andWhere('v.id != :exclu')->setParameter('exclu', $exclureVisite, 'uuid');
        }

        $qb->setMaxResults(1);

        return $qb->getQuery()->getOneOrNullResult() !== null;
    }
}
