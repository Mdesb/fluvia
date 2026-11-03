<?php

declare(strict_types=1);

namespace App\Tests\SmartFlow\Api;

use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Platform\Event\DomainEvent;
use App\Platform\Event\EventBus;
use App\Platform\Event\EventSubject;
use App\Platform\Event\EventTenant;
use App\Reservation\DataFixtures\ReservationFixtures;
use App\Reservation\Entity\Creneau;
use App\Reservation\Entity\Ressource;
use App\Reservation\Enum\StatutCreneau;
use App\SmartFlow\Entity\RescheduleProposal;
use App\SmartFlow\Entity\SlotReleaseTrace;
use App\SmartFlow\Entity\SlotWaitlistEntry;
use App\SmartFlow\Enum\SlotWaitlistEntryStatus;
use App\Tests\Platform\ModuleSwitch;
use App\Tests\SmartFlow\SmartFlowApiTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Recentrage, PR 2 (d) — les trois écouteurs de Smart Flow n'écrivent plus pour un établissement où
 * le module est éteint.
 *
 * Chacun est éprouvé par son effet réel en base, l'événement publié par le vrai bus : éteint pour A,
 * rien n'est écrit ; allumé, l'écriture a lieu (témoin, sans lequel « rien » pourrait venir d'un
 * montage faux).
 */
final class SwitchedOffSmartFlowListenersTest extends SmartFlowApiTestCase
{
    use ModuleSwitch;

    /** `SlotFreedListener` : `booking.cancelled` écrit une trace de libération. */
    public function testSlotFreedListenerWritesNothingWhenSmartFlowIsOff(): void
    {
        foreach ([false, true] as $on) {
            $this->switchModule('smart_flow', $on, SocleFixtures::ETAB_A_NOM);
            $slot = $this->slot(new \DateTimeImmutable('+1 day'));

            $this->publish('booking.cancelled', ['slotId' => (string) $slot->getId(), 'leadTimeMinutes' => 120, 'withinFreeWindow' => true]);

            $traces = $this->em()->getRepository(SlotReleaseTrace::class)->count(['slotId' => $slot->getId()]);
            self::assertSame($on ? 1 : 0, $traces, $on ? 'témoin : allumé, la trace est écrite' : 'éteint, rien n\'est écrit');
        }
    }

    /** `SlotReleasedListener` : `slot.released` promeut le premier de la liste d'attente. */
    public function testSlotReleasedListenerPromotesNobodyWhenSmartFlowIsOff(): void
    {
        foreach ([false, true] as $on) {
            $this->switchModule('smart_flow', $on, SocleFixtures::ETAB_A_NOM);
            $resource = Uuid::v4();
            $entry = (new SlotWaitlistEntry())
                ->setEstablishment($this->establishmentA())
                ->setResourceId($resource)
                ->setBeneficiaryId(Uuid::v4())
                ->setSearchWindowStart(new \DateTimeImmutable('+1 day'))
                ->setSearchWindowEnd(new \DateTimeImmutable('+7 days'))
                ->setRank(1)
                ->setStatus(SlotWaitlistEntryStatus::Waiting);
            $this->em()->persist($entry);
            $this->em()->flush();

            $this->publish('slot.released', ['slot' => (string) Uuid::v4(), 'resource' => (string) $resource]);

            $this->em()->refresh($entry);
            self::assertSame($on ? SlotWaitlistEntryStatus::Promoted : SlotWaitlistEntryStatus::Waiting, $entry->getStatus());
        }
    }

    /** `RescheduleRequestedListener` : `booking.reschedule_requested` ouvre une proposition de report. */
    public function testRescheduleRequestedListenerOpensNoProposalWhenSmartFlowIsOff(): void
    {
        foreach ([false, true] as $on) {
            $this->switchModule('smart_flow', $on, SocleFixtures::ETAB_A_NOM);
            $before = $this->em()->getRepository(RescheduleProposal::class)->count([]);
            $ref = (string) Uuid::v4();

            $this->publish('booking.reschedule_requested', [
                'customerId' => $this->idBeneficiairePayeur(),
                'reservationRef' => $ref,
                'slotId' => (string) $this->slot(new \DateTimeImmutable('+1 day'))->getId(),
                'droitId' => (string) Uuid::v4(),
            ], $ref);

            $after = $this->em()->getRepository(RescheduleProposal::class)->count([]);
            self::assertSame($on ? $before + 1 : $before, $after);
        }
    }

    /**
     * PR 2 (e) — `smart-flow:waitlist:expirer` ne traite que les établissements où le module est
     * allumé : une promotion échue chez A, module éteint, reste telle quelle.
     */
    public function testExpiryLeavesTheProposalsOfASwitchedOffEstablishmentAlone(): void
    {
        /** @var \App\SmartFlow\Service\SlotWaitlistPromotionService $promotions */
        $promotions = static::getContainer()->get(\App\SmartFlow\Service\SlotWaitlistPromotionService::class);
        /** @var \App\SmartFlow\Command\ExpireSlotWaitlistPromotionsCommand $command */
        $command = static::getContainer()->get(\App\SmartFlow\Command\ExpireSlotWaitlistPromotionsCommand::class);

        foreach ([false, true] as $on) {
            $resource = Uuid::v4();
            $entry = (new SlotWaitlistEntry())->setEstablishment($this->establishmentA())->setResourceId($resource)
                ->setBeneficiaryId(Uuid::v4())->setSearchWindowStart(new \DateTimeImmutable('+1 day'))
                ->setSearchWindowEnd(new \DateTimeImmutable('+7 days'))->setRank(1)->setStatus(SlotWaitlistEntryStatus::Waiting);
            $this->em()->persist($entry);
            $this->em()->flush();
            $proposal = $promotions->promoteNext($this->establishmentA(), $resource, Uuid::v4(), new \DateTimeImmutable());
            self::assertInstanceOf(RescheduleProposal::class, $proposal);
            $proposal->setExpiresAt(new \DateTimeImmutable('-1 minute'));
            $this->em()->flush();

            $this->switchModule('smart_flow', $on, SocleFixtures::ETAB_A_NOM);
            $status = $proposal->getStatus();
            $command->expirer(new \DateTimeImmutable());
            $this->em()->refresh($proposal);

            if ($on) {
                self::assertNotSame($status, $proposal->getStatus(), 'témoin : allumé, la promotion échue est close');
            } else {
                self::assertSame($status, $proposal->getStatus(), 'éteint, rien n\'est touché');
            }
        }
    }

    /** @param array<string, scalar> $payload */
    private function publish(string $name, array $payload, ?string $subject = null): void
    {
        /** @var EventBus $bus */
        $bus = static::getContainer()->get(EventBus::class);
        $bus->publish(new DomainEvent(
            $name,
            new EventTenant($this->establishmentA()->getId()),
            new EventSubject('Reservation', $subject ?? (string) Uuid::v4()),
            $payload,
        ));
    }

    private function slot(\DateTimeImmutable $start): Creneau
    {
        $resource = $this->em()->getRepository(Ressource::class)->findOneBy(['libelle' => ReservationFixtures::RESSOURCE_SALLE_LIBELLE]);
        self::assertInstanceOf(Ressource::class, $resource);
        $slot = (new Creneau())->setRessource($resource)->setDebut($start)->setFin($start->modify('+60 minutes'))
            ->setCapacite(2)->setEtablissement($this->establishmentA())->setStatut(StatutCreneau::Planifie);
        $this->em()->persist($slot);
        $this->em()->flush();

        return $slot;
    }

    private function establishmentA(): Etablissement
    {
        $establishment = $this->em()->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        self::assertInstanceOf(Etablissement::class, $establishment);

        return $establishment;
    }
}
