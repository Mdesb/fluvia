<?php

declare(strict_types=1);

namespace App\SmartFlow\Service;

use App\Organisation\Entity\Etablissement;
use App\Platform\Notification\ClientNotification;
use App\Platform\Notification\ClientNotifierInterface;
use App\Platform\Notification\NotificationBasis;
use App\Platform\Notification\NotificationChannel;
use App\SmartFlow\Entity\RescheduleProposal;
use App\SmartFlow\Entity\SlotWaitlistEntry;
use App\SmartFlow\Enum\RescheduleProposalStatus;
use App\SmartFlow\Enum\SlotWaitlistEntryStatus;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
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
        private readonly ClientNotifierInterface $notifier,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Tente une promotion sur la ressource `$resourceId` pour le créneau libéré `$slotId` (RG-SF-06) —
     * `null` si aucune inscription `waiting` n'existe sur cette ressource (pas un échec, un simple
     * constat : la revente non ciblée, hors périmètre de ce lot, peut alors s'appliquer).
     *
     * `$occurredAt` (D37) est l'instant métier de la promotion : celui de `slot.released` quand
     * l'appelant est `App\SmartFlow\EventListener\SlotReleasedListener`, celui de l'exécution de la
     * tâche planifiée quand l'appelant est `App\SmartFlow\Command\ExpireSlotWaitlistPromotionsCommand`
     * (l'expiration constatée déclenche elle-même la promotion suivante, il n'existe pas d'instant
     * antérieur plus légitime).
     */
    public function promoteNext(Etablissement $establishment, Uuid $resourceId, Uuid $slotId, \DateTimeImmutable $occurredAt): ?RescheduleProposal
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

        try {
            // ⚠ BASE LÉGALE À CONFIRMER PAR claude-A : `Consentement` par défaut (le plus strict), même
            // question que `RescheduleRequestedListener` pour une proposition de report après no-show.
            $this->notifier->notify(new ClientNotification(
                $entry->getBeneficiaryId(),
                NotificationChannel::Email,
                'smart_flow.waitlist_promoted',
                [
                    'entryId' => $entry->getId()->toRfc4122(),
                    'promotedProposalRef' => $entry->getPromotedProposalRef()?->toRfc4122(),
                ],
                $occurredAt,
                'smart_flow',
                NotificationBasis::Consentement,
            ));
        } catch (\Throwable $error) {
            // Best-effort (D7) : un appelant console (`ExpireSlotWaitlistPromotionsCommand`) n'est pas
            // enveloppé par le try/catch d'un listener — la promotion elle-même (déjà persistée) ne doit
            // jamais échouer parce que la notification a échoué.
            $this->logger?->error('smart_flow.notification.failed', [
                'entry' => (string) $entry->getId(),
                'reason' => $error->getMessage(),
            ]);
        }

        return $proposal;
    }
}
