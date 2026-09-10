<?php

declare(strict_types=1);

namespace App\Tests\SmartFlow\Api;

use App\DataFixtures\SocleFixtures;
use App\Crm\Entity\Beneficiaire;
use App\Organisation\Entity\Etablissement;
use App\Platform\Event\DomainEvent;
use App\Platform\Event\EventBus;
use App\Platform\Event\EventSubject;
use App\Platform\Event\EventTenant;
use App\Platform\Notification\ClientNotification;
use App\Platform\Notification\ClientNotifierInterface;
use App\Platform\Notification\NotificationBasis;
use App\Platform\Notification\NotificationChannel;
use App\Platform\Notification\NotificationOutcome;
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

    /**
     * Migration `App\Platform\Notification\ClientNotifierInterface` (port transverse, remplace
     * `App\SmartFlow\Port\ClientNotificationInterface` supprimé) : vérifie canal, gabarit, source, base
     * légale et instant métier (D37, celui de `slot.released`) transmis à `notify()` lors d'une promotion.
     */
    public function testNotificationDeLaPromotionPasseParLePortTransverseEtPorteLinstantMetierDeSlotReleased(): void
    {
        $idA = $this->establishmentId(SocleFixtures::ETAB_A_NOM);
        $etablissement = $this->em()->getRepository(Etablissement::class)->find(Uuid::fromString($idA));
        self::assertInstanceOf(Etablissement::class, $etablissement);

        $resourceId = Uuid::v4();
        // ⚠ UN BÉNÉFICIAIRE RÉEL, PAS UN `Uuid::v4()`. Ce test fabriquait un identifiant que le
        // monde réel ne produit jamais : aucun bénéficiaire derrière, donc aucun client, donc un
        // message qui n'aurait jamais pu être délivré. Il mesurait le code, pas le produit.
        $idBeneficiaire = Uuid::fromString($this->idBeneficiairePayeur());
        $entree1 = $this->creerEntree($etablissement, $resourceId, 1, $idBeneficiaire);

        $espion = $this->espionnerNotifier();

        $slotId = Uuid::v4();
        $occurredAt = new \DateTimeImmutable('2026-08-21 09:30:00');
        $this->publierSlotReleased($idA, $slotId, $resourceId, $occurredAt);

        self::assertCount(1, $espion->recues, 'RG-SF-06 : une promotion doit notifier le bénéficiaire promu.');
        $notification = $espion->recues[0];

        // ⚠ LE CLIENT DU BÉNÉFICIAIRE, PAS LE BÉNÉFICIAIRE. `ClientNotification::$clientId` attend un
        // `Client` ; `ConsentGatedNotifier` fait `Client::find()` dessus et rend `Refusee` s'il ne
        // trouve rien — indiscernable d'un refus de consentement. Ce test affirmait l'égalité des
        // deux jusqu'au 06/09, c'est-à-dire qu'il figeait le défaut.
        $beneficiaire = $this->em()->getRepository(Beneficiaire::class)->find($entree1->getBeneficiaryId());
        self::assertNotNull($beneficiaire?->getClient(), 'Le montage suppose un bénéficiaire rattaché à un client.');
        self::assertSame(
            (string) $beneficiaire->getClient()->getId(),
            $notification->clientId->toRfc4122(),
            'La notification doit porter le client, sinon personne ne la reçoit.',
        );
        self::assertNotSame(
            (string) $entree1->getBeneficiaryId(),
            $notification->clientId->toRfc4122(),
            'Bénéficiaire et client sont deux identités : les confondre rendait le message indélivrable.',
        );
        self::assertSame(NotificationChannel::Email, $notification->channel);
        self::assertSame('smart_flow.waitlist_promoted', $notification->templateKey);
        self::assertSame('smart_flow', $notification->source);
        self::assertSame(NotificationBasis::Consentement, $notification->basis, '⚠ à confirmer par claude-A (défaut le plus strict).');
        self::assertEquals($occurredAt, $notification->occurredAt, 'D37 : instant métier = celui de slot.released.');
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
        $proposition1 = $promotionService->promoteNext($etablissement, $resourceId, $slotId, new \DateTimeImmutable());
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

    private function creerEntree(
        Etablissement $etablissement,
        Uuid $resourceId,
        int $rank,
        ?Uuid $beneficiaryId = null,
    ): SlotWaitlistEntry {
        $entry = (new SlotWaitlistEntry())
            ->setEstablishment($etablissement)
            ->setResourceId($resourceId)
            // Le défaut reste un identifiant fabriqué : les autres tests de ce fichier mesurent le
            // RANG et l'ordre, pas la délivrabilité, et un bénéficiaire réel n'y ajouterait rien.
            ->setBeneficiaryId($beneficiaryId ?? Uuid::v4())
            ->setSearchWindowStart(new \DateTimeImmutable('+1 day'))
            ->setSearchWindowEnd(new \DateTimeImmutable('+7 days'))
            ->setRank($rank)
            ->setStatus(SlotWaitlistEntryStatus::Waiting);

        $this->em()->persist($entry);
        $this->em()->flush();

        return $entry;
    }

    private function publierSlotReleased(string $idEtablissement, Uuid $slotId, Uuid $resourceId, ?\DateTimeImmutable $occurredAt = null): void
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
            null,
            $occurredAt,
        ));
    }

    /**
     * Remplace `ClientNotifierInterface` par un espion qui journalise et rend `Journalisee` (même patron
     * que `App\Tests\Subscription\Integration\CourrielDeBienvenueTest::espionner()`).
     *
     * @return object{recues: list<ClientNotification>}
     */
    private function espionnerNotifier(): object
    {
        $espion = new class implements ClientNotifierInterface {
            /** @var list<ClientNotification> */
            public array $recues = [];

            public function notify(ClientNotification $notification): NotificationOutcome
            {
                $this->recues[] = $notification;

                return NotificationOutcome::Journalisee;
            }
        };

        static::getContainer()->set(ClientNotifierInterface::class, $espion);

        return $espion;
    }
}
