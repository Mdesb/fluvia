<?php

declare(strict_types=1);

namespace App\Tests\SmartFlow\Api;

use App\Crm\Entity\Beneficiaire;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Platform\Event\DomainEvent;
use App\Platform\Event\EventBus;
use App\Platform\Event\EventSubject;
use App\Platform\Event\EventTenant;
use App\Reservation\DataFixtures\ReservationFixtures;
use App\Reservation\Entity\Creneau;
use App\Reservation\Entity\Reservation;
use App\Reservation\Entity\Ressource;
use App\Reservation\Enum\StatutCreneau;
use App\SmartFlow\Entity\SlotReleaseTrace;
use App\Tests\SmartFlow\SmartFlowApiTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Uid\Uuid;

/**
 * `SlotFreedListener` (RG-SF-01..04, plan-smart-flow.md T8) : `booking.cancelled`/`booking.no_show`
 * déclenchent `slot.released` seulement si la place reste réellement libre après relecture
 * (RG-SF-02/03), et une seule fois par `(slotId, subject.id)` (idempotence, RG-SF-04, CA-9).
 */
final class SlotReleaseListenerTest extends SmartFlowApiTestCase
{
    public function testBookingCancelledPubliesSlotReleasedSiPlaceRestante(): void
    {
        $idA = $this->establishmentId(SocleFixtures::ETAB_A_NOM);
        $creneau = $this->creerCreneau($idA, 2);

        $compteur = $this->compterSlotReleased();
        $this->publierBookingCancelled($idA, (string) Uuid::v4(), $creneau->getId());

        self::assertSame(1, $compteur(), 'RG-SF-01/03 : place résiduelle > 0 -> slot.released publié.');

        $trace = $this->em()->getRepository(SlotReleaseTrace::class)->findOneBy(['slotId' => $creneau->getId()]);
        self::assertNotNull($trace);
        self::assertTrue($trace->isReleased());
    }

    public function testPromotionListeAttenteInterneDejaConsommeeNePubliePas(): void
    {
        $idA = $this->establishmentId(SocleFixtures::ETAB_A_NOM);
        $creneau = $this->creerCreneau($idA, 1);
        $this->creerReservationConfirmee($idA, $creneau);

        $compteur = $this->compterSlotReleased();
        $this->publierBookingCancelled($idA, (string) Uuid::v4(), $creneau->getId());

        self::assertSame(0, $compteur(), 'RG-SF-02 : la liste d\'attente interne a déjà repris la place -> pas de slot.released.');

        $trace = $this->em()->getRepository(SlotReleaseTrace::class)->findOneBy(['slotId' => $creneau->getId()]);
        self::assertNotNull($trace, 'Une trace est tout de même écrite (RG-SF-04), released=false.');
        self::assertFalse($trace->isReleased());
    }

    public function testIdempotenceMemeTriggerNePublieQuUneFois(): void
    {
        $idA = $this->establishmentId(SocleFixtures::ETAB_A_NOM);
        $creneau = $this->creerCreneau($idA, 2);
        $subjectId = (string) Uuid::v4();

        $compteur = $this->compterSlotReleased();
        $this->publierBookingCancelled($idA, $subjectId, $creneau->getId());
        $this->publierBookingCancelled($idA, $subjectId, $creneau->getId()); // rejeu, même subject.id

        self::assertSame(1, $compteur(), 'RG-SF-04/CA-9 : même (slotId, subject.id) -> une seule publication.');

        $traces = $this->em()->getRepository(SlotReleaseTrace::class)->findBy(['slotId' => $creneau->getId()]);
        self::assertCount(1, $traces, 'Contrainte unique (slot_id, trigger_subject_id) : une seule trace persistée.');
    }

    /** @return callable(): int */
    private function compterSlotReleased(): callable
    {
        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = static::getContainer()->get(EventDispatcherInterface::class);
        $compte = 0;
        $dispatcher->addListener('slot.released', function () use (&$compte): void {
            ++$compte;
        });

        return static function () use (&$compte): int {
            return $compte;
        };
    }

    private function publierBookingCancelled(string $idEtablissement, string $subjectId, Uuid $slotId): void
    {
        /** @var EventBus $bus */
        $bus = static::getContainer()->get(EventBus::class);
        $bus->publish(new DomainEvent(
            'booking.cancelled',
            new EventTenant(Uuid::fromString($idEtablissement)),
            new EventSubject('Reservation', $subjectId),
            [
                'slotId' => (string) $slotId,
                'leadTimeMinutes' => 120,
                'withinFreeWindow' => true,
            ],
        ));
    }

    private function creerCreneau(string $idEtablissement, int $capacite): Creneau
    {
        $em = $this->em();
        $etablissement = $em->getRepository(Etablissement::class)->find(Uuid::fromString($idEtablissement));
        self::assertInstanceOf(Etablissement::class, $etablissement);
        $ressource = $em->getRepository(Ressource::class)->findOneBy(['libelle' => ReservationFixtures::RESSOURCE_SALLE_LIBELLE]);
        self::assertInstanceOf(Ressource::class, $ressource);

        $debut = new \DateTimeImmutable('+1 day');
        $creneau = (new Creneau())->setRessource($ressource)
            ->setDebut($debut)->setFin($debut->modify('+60 minutes'))
            ->setCapacite($capacite)->setEtablissement($etablissement)->setStatut(StatutCreneau::Planifie);
        $em->persist($creneau);
        $em->flush();

        return $creneau;
    }

    private function creerReservationConfirmee(string $idEtablissement, Creneau $creneau): void
    {
        $em = $this->em();
        $etablissement = $em->getRepository(Etablissement::class)->find(Uuid::fromString($idEtablissement));
        self::assertInstanceOf(Etablissement::class, $etablissement);
        $beneficiaire = $em->getRepository(Beneficiaire::class)->find(Uuid::fromString($this->idBeneficiairePayeur()));
        self::assertInstanceOf(Beneficiaire::class, $beneficiaire);

        $reservation = (new Reservation())
            ->setCreneau($creneau)
            ->setOrganisateur($beneficiaire)
            ->setEtablissement($etablissement);
        $em->persist($reservation);
        $em->flush();
    }
}
