<?php

declare(strict_types=1);

namespace App\Reservation\Service;

use App\Reservation\Entity\Creneau;
use App\Reservation\Entity\Ressource;
use App\Reservation\Port\NotificationReservationInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Report automatique d'une occurrence en conflit sur une ressource alternative équivalente (même
 * type, capacité ≥, même fenêtre horaire), RG-M5-11/CA-7. À défaut, bascule en validation manuelle
 * (`Creneau.enAttenteArbitrage`) avec notification du bénéficiaire (organisateur non connu à ce
 * niveau générique : notification portée par `ArbitrerConflitRecurrenceProcessor` au niveau
 * réservation).
 */
final class RecurrenceReportHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ChevauchementCreneauGuard $guard,
        private readonly NotificationReservationInterface $notification,
    ) {
    }

    /** Vrai si un report automatique a été appliqué ; faux si basculé en validation manuelle. */
    public function tenterReport(Creneau $creneau): bool
    {
        $ressourceOriginale = $creneau->getRessource();
        $etablissement = $ressourceOriginale?->getEtablissement();
        if ($ressourceOriginale === null || $etablissement === null) {
            $creneau->setEnAttenteArbitrage(true);

            return false;
        }

        /** @var list<Ressource> $alternatives */
        $alternatives = $this->em->getRepository(Ressource::class)->createQueryBuilder('r')
            ->andWhere('r.etablissement = :etab')
            ->andWhere('r.codeType = :type')
            ->andWhere('r.capacitePropre >= :capaciteMin')
            ->andWhere('r.id != :original')
            ->andWhere('r.actif = true')
            ->setParameter('etab', $etablissement->getId(), 'uuid')
            ->setParameter('type', $ressourceOriginale->getCodeType())
            ->setParameter('capaciteMin', $creneau->getCapacite())
            ->setParameter('original', $ressourceOriginale->getId(), 'uuid')
            ->getQuery()
            ->getResult();

        foreach ($alternatives as $alternative) {
            if (!$this->guard->enConflit($alternative, $creneau->getDebut(), $creneau->getFin(), $creneau->getId())) {
                $creneau->setRessource($alternative);
                $creneau->setOccurrenceModifiee(true);
                $creneau->setEnAttenteArbitrage(false);
                $this->em->flush();

                return true;
            }
        }

        $creneau->setEnAttenteArbitrage(true);
        $this->em->flush();

        return false;
    }
}
