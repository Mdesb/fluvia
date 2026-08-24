<?php

declare(strict_types=1);

namespace App\Reservation\Service;

use App\Reservation\Entity\Creneau;
use App\Reservation\Entity\ListeAttente;
use App\Reservation\Entity\Reservation;
use App\Reservation\Enum\ModeDecompteReservation;
use App\Reservation\Enum\StatutListeAttente;
use App\Reservation\Enum\StatutReservation;
use App\Reservation\Port\NotificationReservationInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Promotion automatique de la liste d'attente au désistement (RG-M5-06, CA-5) : sans validation
 * manuelle. Le délai de confirmation de la promotion (défaut 15 min, §7 point 11 — ⚠ HYPOTHÈSE,
 * non chiffré par les sources) est porté par `ListeAttente.dateExpirationPromotion` ;
 * `expirerPromotionsDepassees()` matérialise l'expiration : la réservation issue de la promotion
 * (si toujours confirmée et sans présence) est libérée et le rang suivant promu.
 */
final class PromotionListeAttenteHandler
{
    private const DELAI_CONFIRMATION_MINUTES = 15;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly JaugeCreneauGuard $jauge,
        private readonly NotificationReservationInterface $notification,
    ) {
    }

    public function promouvoirSiPlaceDisponible(Creneau $creneau): ?Reservation
    {
        if ($this->jauge->estComplet($creneau)) {
            return null;
        }

        /** @var ListeAttente|null $inscription */
        $inscription = $this->em->getRepository(ListeAttente::class)->createQueryBuilder('l')
            ->andWhere('l.creneau = :creneau')
            ->andWhere('l.statut = :statut')
            ->setParameter('creneau', $creneau->getId(), 'uuid')
            ->setParameter('statut', StatutListeAttente::EnAttente->value)
            ->orderBy('l.rang', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($inscription === null) {
            return null;
        }

        // ACT-1 — le premier de la liste peut demander plus que ce qui vient de se libérer. On ne
        // promeut alors PERSONNE : sauter au suivant qui « rentre » romprait le premier arrivé
        // premier servi de RG-M5-06, et ce n'est pas à moi d'arbitrer une politique commerciale que
        // ni D16 ni RG-M5-06 ne posent. Question ouverte à claude-A dans mon rapport.
        if (!$this->jauge->peutAccueillir($creneau, $inscription->getQuantity())) {
            return null;
        }

        $reservation = new Reservation();
        $reservation->setCreneau($creneau)
            ->setOrganisateur($inscription->getBeneficiaire())
            ->setEtablissement($creneau->getEtablissement())
            ->setModeDecompte(ModeDecompteReservation::Gratuit)
            ->setQuantity($inscription->getQuantity())
            ->setMontantDu('0.00');
        $this->em->persist($reservation);

        $inscription->setStatut(StatutListeAttente::Promue);
        $inscription->setPromueEn($reservation);
        $inscription->setDateExpirationPromotion((new \DateTimeImmutable())->modify(sprintf('+%d minutes', self::DELAI_CONFIRMATION_MINUTES)));

        $this->notification->notifierPromotionListeAttente($inscription);
        $this->em->flush();

        return $reservation;
    }

    /** @return int nombre de promotions expirées traitées */
    public function expirerPromotionsDepassees(\DateTimeImmutable $maintenant): int
    {
        /** @var list<ListeAttente> $expirees */
        $expirees = $this->em->getRepository(ListeAttente::class)->createQueryBuilder('l')
            ->andWhere('l.statut = :statut')
            ->andWhere('l.dateExpirationPromotion IS NOT NULL')
            ->andWhere('l.dateExpirationPromotion < :maintenant')
            ->setParameter('statut', StatutListeAttente::Promue->value)
            ->setParameter('maintenant', $maintenant, 'datetime_immutable')
            ->getQuery()
            ->getResult();

        foreach ($expirees as $inscription) {
            $reservation = $inscription->getPromueEn();
            if ($reservation !== null && $reservation->getStatut() === StatutReservation::Confirmee && !$reservation->isPresenceConfirmee()) {
                $reservation->setStatut(StatutReservation::AnnuleeLibre);
            }
            $inscription->setStatut(StatutListeAttente::Expiree);
        }
        $this->em->flush();

        foreach ($expirees as $inscription) {
            $creneau = $inscription->getCreneau();
            if ($creneau !== null) {
                $this->promouvoirSiPlaceDisponible($creneau);
            }
        }

        return \count($expirees);
    }
}
