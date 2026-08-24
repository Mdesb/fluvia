<?php

declare(strict_types=1);

namespace App\Tests\SmartFlow\Api;

use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Platform\Event\DomainEvent;
use App\Platform\Event\EventBus;
use App\Platform\Event\EventSubject;
use App\Platform\Event\EventTenant;
use App\SmartFlow\Command\ExpireSlotWaitlistPromotionsCommand;
use App\SmartFlow\Entity\RescheduleProposal;
use App\SmartFlow\Entity\SlotWaitlistEntry;
use App\SmartFlow\Enum\RescheduleProposalStatus;
use App\SmartFlow\Enum\SlotWaitlistEntryStatus;
use App\SmartFlow\Service\SlotWaitlistPromotionService;
use App\Tests\SmartFlow\SmartFlowApiTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Promotion FIFO de la liste d'attente Smart Flow (RG-SF-05..07, plan-smart-flow.md T9) : réception de
 * `slot.released` (auto-consommé, RG-SF-06) et expiration sans confirmation (RG-SF-07,
 * `smart-flow:waitlist:expirer`).
 */
final class SlotWaitlistPromotionTest extends SmartFlowApiTestCase
{
    public function testPromotionFifoSurRessourceALaReceptionSlotReleased(): void
    {
        $idA = $this->establishmentId(SocleFixtures::ETAB_A_NOM);
        $etablissement = $this->em()->getRepository(Etablissement::class)->find(Uuid::fromString($idA));
        self::assertInstanceOf(Etablissement::class, $etablissement);

        $resourceId = Uuid::v4();
        $entree1 = $this->creerEntree($etablissement, $resourceId, 1);
        $entree2 = $this->creerEntree($etablissement, $resourceId, 2);

        $slotId = Uuid::v4();
        $this->publierSlotReleased($idA, $slotId, $resourceId);

        $em = $this->em();
        $em->refresh($entree1);
        $em->refresh($entree2);

        self::assertSame(SlotWaitlistEntryStatus::Promoted, $entree1->getStatus(), 'RG-SF-06 : FIFO -> le candidat le plus ancien (rank 1) est promu.');
        self::assertSame(SlotWaitlistEntryStatus::Waiting, $entree2->getStatus(), 'Le second candidat reste en attente.');
        self::assertNotNull($entree1->getPromotedProposalRef());

        $proposition = $em->getRepository(RescheduleProposal::class)->find($entree1->getPromotedProposalRef());
        self::assertInstanceOf(RescheduleProposal::class, $proposition);
        self::assertSame(RescheduleProposalStatus::Proposed, $proposition->getStatus());
        self::assertSame((string) $slotId, (string) $proposition->getProposedSlotId());
        self::assertSame((string) $entree1->getBeneficiaryId(), (string) $proposition->getCustomerId());
        self::assertSame((string) $entree1->getId(), (string) $proposition->getSourceWaitlistEntryRef());
        self::assertNull($proposition->getOriginReservationRef(), 'Une promotion liste d\'attente ne trace aucun no-show d\'origine.');
    }

    public function testExpirationSansConfirmationTenteLInscriptionSuivante(): void
    {
        $idA = $this->establishmentId(SocleFixtures::ETAB_A_NOM);
        $etablissement = $this->em()->getRepository(Etablissement::class)->find(Uuid::fromString($idA));
        self::assertInstanceOf(Etablissement::class, $etablissement);

        $resourceId = Uuid::v4();
        $entree1 = $this->creerEntree($etablissement, $resourceId, 1);
        $entree2 = $this->creerEntree($etablissement, $resourceId, 2);

        /** @var SlotWaitlistPromotionService $promotionService */
        $promotionService = static::getContainer()->get(SlotWaitlistPromotionService::class);
        $slotId = Uuid::v4();
        $proposition1 = $promotionService->promoteNext($etablissement, $resourceId, $slotId);
        self::assertInstanceOf(RescheduleProposal::class, $proposition1);

        // Force l'expiration (RG-SF-07 : délai de confirmation dépassé sans accept/decline).
        $proposition1->setExpiresAt(new \DateTimeImmutable('-1 minute'));
        $this->em()->flush();

        /** @var ExpireSlotWaitlistPromotionsCommand $commande */
        $commande = static::getContainer()->get(ExpireSlotWaitlistPromotionsCommand::class);
        $traites = $commande->expirer(new \DateTimeImmutable());
        self::assertSame(1, $traites);

        $em = $this->em();
        $em->refresh($proposition1);
        $em->refresh($entree1);
        $em->refresh($entree2);

        self::assertSame(RescheduleProposalStatus::Expired, $proposition1->getStatus());
        self::assertSame(SlotWaitlistEntryStatus::Expired, $entree1->getStatus(), 'RG-SF-07 : inscription expirée avec sa promotion.');
        self::assertSame(SlotWaitlistEntryStatus::Promoted, $entree2->getStatus(), 'RG-SF-07 : l\'inscription suivante est tentée.');
        self::assertNotNull($entree2->getPromotedProposalRef());

        $proposition2 = $em->getRepository(RescheduleProposal::class)->find($entree2->getPromotedProposalRef());
        self::assertInstanceOf(RescheduleProposal::class, $proposition2);
        self::assertSame(RescheduleProposalStatus::Proposed, $proposition2->getStatus());
    }

    private function creerEntree(Etablissement $etablissement, Uuid $resourceId, int $rank): SlotWaitlistEntry
    {
        $entry = (new SlotWaitlistEntry())
            ->setEstablishment($etablissement)
            ->setResourceId($resourceId)
            ->setBeneficiaryId(Uuid::v4())
            ->setSearchWindowStart(new \DateTimeImmutable('+1 day'))
            ->setSearchWindowEnd(new \DateTimeImmutable('+7 days'))
            ->setRank($rank)
            ->setStatus(SlotWaitlistEntryStatus::Waiting);

        $this->em()->persist($entry);
        $this->em()->flush();

        return $entry;
    }

    private function publierSlotReleased(string $idEtablissement, Uuid $slotId, Uuid $resourceId): void
    {
        /** @var EventBus $bus */
        $bus = static::getContainer()->get(EventBus::class);
        $bus->publish(new DomainEvent(
            'slot.released',
            new EventTenant(Uuid::fromString($idEtablissement)),
            new EventSubject('Slot', (string) $slotId),
            [
                'slot' => (string) $slotId,
                'resource' => (string) $resourceId,
            ],
        ));
    }
}
