<?php

declare(strict_types=1);

namespace App\SmartFlow\Port\Adapter;

use App\SmartFlow\Entity\RescheduleProposal;
use App\SmartFlow\Entity\SlotWaitlistEntry;
use App\SmartFlow\Port\ClientNotificationInterface;
use Psr\Log\LoggerInterface;

/** Implémentation par défaut (D19) : journalise uniquement (aucun canal email/SMS/push spécifié dans ce dépôt). */
final class ClientNotificationLogAdapter implements ClientNotificationInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public function notifyRescheduleProposal(RescheduleProposal $proposal): void
    {
        $this->logger->info('smart_flow.notification.reschedule_proposal', [
            'proposal' => (string) $proposal->getId(),
            'customerId' => (string) $proposal->getCustomerId(),
            'proposedSlotId' => (string) $proposal->getProposedSlotId(),
        ]);
    }

    public function notifyWaitlistPromotion(SlotWaitlistEntry $entry): void
    {
        $this->logger->info('smart_flow.notification.waitlist_promotion', [
            'entry' => (string) $entry->getId(),
            'beneficiaryId' => (string) $entry->getBeneficiaryId(),
            'promotedProposalRef' => (string) $entry->getPromotedProposalRef(),
        ]);
    }
}
