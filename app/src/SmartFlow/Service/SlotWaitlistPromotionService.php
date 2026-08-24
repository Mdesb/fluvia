<?php

declare(strict_types=1);

namespace App\SmartFlow\Service;

use App\Organisation\Entity\Etablissement;
use App\SmartFlow\Entity\RescheduleProposal;
use App\SmartFlow\Entity\SlotWaitlistEntry;
use App\SmartFlow\Enum\RescheduleProposalStatus;
use App\SmartFlow\Enum\SlotWaitlistEntryStatus;
use App\SmartFlow\Port\ClientNotificationInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Promotion FIFO de la liste d'attente Smart Flow (RG-SF-06/07, plan-smart-flow.md T9) : matérialise
 * une `RescheduleProposal` (`sourceWaitlistEntryRef` renseigné, `originReservationRef`/`entitlementRef`
 * nuls — §1 note de conception, une promotion Smart Flow ne retrace aucun crédit) pour le candidat le
 * plus ancien (`rank` croissant, `status = waiting`) de la ressource libérée, avec un délai de
 * confirmation court (⚠ HYPOTHÈSE non chiffrée par une source produit, plan §0.7 : 15 minutes par
 * défaut, même ordre de grandeur que `PromotionListeAttenteHandler::DELAI_CONFIRMATION_MINUTES` côté
 * `App\Reservation`).
 *
 * Réutilisée par `App\SmartFlow\EventListener\SlotReleasedListener` (auto-consommé, RG-SF-06) **et**
 * par `App\SmartFlow\Command\ExpireSlotWaitlistPromotionsCommand` (tente l'inscription suivante quand
 * la précédente expire sans confirmation, RG-SF-07).
 */
final class SlotWaitlistPromotionService
{
    /** ⚠ HYPOTHÈSE non chiffrée par une source produit (plan §0.7) — à rendre paramétrable par établissement. */
    private const PROMOTION_EXPIRATION_MINUTES = 15;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ClientNotificationInterface $notifier,
    ) {
    }

    /**
     * Tente une promotion sur la ressource `$resourceId` pour le créneau libéré `$slotId` (RG-SF-06) —
     * `null` si aucune inscription `waiting` n'existe sur cette ressource (pas un échec, un simple
     * constat : la revente non ciblée, hors périmètre de ce lot, peut alors s'appliquer).
     */
    public function promoteNext(Etablissement $establishment, Uuid $resourceId, Uuid $slotId): ?RescheduleProposal
    {
        /** @var SlotWaitlistEntry|null $entry */
        $entry = $this->em->getRepository(SlotWaitlistEntry::class)->createQueryBuilder('e')
            ->andWhere('e.establishment = :establishment')
            ->andWhere('e.resourceId = :resourceId')
            ->andWhere('e.status = :status')
            ->setParameter('establishment', $establishment->getId(), 'uuid')
            ->setParameter('resourceId', $resourceId, 'uuid')
            ->setParameter('status', SlotWaitlistEntryStatus::Waiting->value)
            ->orderBy('e.rank', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$entry instanceof SlotWaitlistEntry) {
            return null;
        }

        $now = new \DateTimeImmutable();

        $proposal = new RescheduleProposal();
        $proposal->setEstablishment($establishment)
            ->setSourceWaitlistEntryRef($entry->getId())
            ->setOriginSlotId($slotId)
            ->setCustomerId($entry->getBeneficiaryId())
            ->setStatus(RescheduleProposalStatus::Proposed)
            ->setProposedSlotId($slotId)
            ->setExpiresAt($now->modify(sprintf('+%d minutes', self::PROMOTION_EXPIRATION_MINUTES)))
            ->setLastSearchAttemptAt($now);

        $entry->setStatus(SlotWaitlistEntryStatus::Promoted)
            ->setPromotedProposalRef($proposal->getId());

        $this->em->persist($proposal);
        $this->em->flush();

        $this->notifier->notifyWaitlistPromotion($entry);

        return $proposal;
    }
}
