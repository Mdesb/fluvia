<?php

declare(strict_types=1);

namespace App\Musee\EventListener;

use App\Musee\Entity\AllocationQuotaOTA;
use App\Musee\Entity\ReservationOTA;
use App\Musee\Enum\StatutReservationOTA;
use App\Musee\Port\ConnecteurOtaInterface;
use App\Reservation\Entity\Reservation;
use App\Reservation\Enum\StatutReservation;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;

/**
 * Récupération du quota des no-show OTA (décision structurante n°5 du plan, US-MUSEE-08, CA-8) :
 * écoute la transition `Reservation.statut → no_show_facture` du module socle Réservation (déclenchée
 * par `reservation:no-show:basculer`, `App\Reservation\Command\BasculerNoShowCommand`, **non
 * modifié**) — si la `Reservation` correspond à une `ReservationOTA`, décrémente
 * `AllocationQuotaOTA.quotaConsomme` et passe `statutOta = recuperee_no_show`, remettant la place à
 * disposition (vente directe ou nouvelle allocation OTA). Aucun pipeline no-show musée parallèle.
 * Même patron additif (`onFlush` + `recomputeSingleEntityChangeSet`) que
 * `App\Acces\EventListener\JaugeFmiSyncListener`.
 */
#[AsDoctrineListener(event: Events::onFlush)]
final class RecupererQuotaOtaNoShowListener
{
    public function __construct(
        private readonly ConnecteurOtaInterface $connecteur,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if (!$entity instanceof Reservation) {
                continue;
            }
            $changeSet = $uow->getEntityChangeSet($entity);
            if (!isset($changeSet['statut'])) {
                continue;
            }
            [, $apres] = $changeSet['statut'];
            // Le changeSet Doctrine porte la valeur scalaire de la colonne (`enumType`), pas
            // l'instance enum — comparaison sur la valeur brute.
            $apresValeur = $apres instanceof StatutReservation ? $apres->value : $apres;
            if ($apresValeur !== StatutReservation::NoShowFacture->value) {
                continue;
            }

            $reservationOta = $em->getRepository(ReservationOTA::class)->findOneBy(['reservationRattachee' => $entity]);
            if (!$reservationOta instanceof ReservationOTA || $reservationOta->getStatutOta() === StatutReservationOTA::RecupereeNoShow) {
                continue;
            }

            $allocation = $reservationOta->getAllocation();
            if ($allocation instanceof AllocationQuotaOTA) {
                $allocation->setQuotaConsomme(max(0, $allocation->getQuotaConsomme() - 1));
                $uow->recomputeSingleEntityChangeSet($em->getClassMetadata(AllocationQuotaOTA::class), $allocation);
            }

            $reservationOta->setStatutOta(StatutReservationOTA::RecupereeNoShow);
            $uow->recomputeSingleEntityChangeSet($em->getClassMetadata(ReservationOTA::class), $reservationOta);

            $this->connecteur->notifierNoShowRecupere($reservationOta);
        }
    }
}
