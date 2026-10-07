<?php

declare(strict_types=1);

namespace App\Tests\Acces\Api;

use App\Acces\Entity\DroitAcces;
use App\Acces\Enum\ModeAppairage;
use App\Acces\Enum\TypeDroitAcces;
use App\Acces\Enum\TypeSupport;
use App\Acces\Service\AppairageHandler;
use App\Personnel\Entity\AffectationTravail;
use App\Personnel\Entity\BadgeStaff;
use App\Personnel\Entity\CreneauTravail;
use App\Personnel\Entity\Employe;
use App\Personnel\Entity\PorteeAccesEmploye;
use App\Personnel\Enum\ModeHoraireBadge;
use App\Personnel\Enum\StatutAffectationTravail;
use App\Personnel\Enum\StatutBadgeStaff;
use App\Personnel\Enum\StatutCreneauTravail;
use App\Personnel\Enum\TypeContrat;
use App\Personnel\Service\RecalculFenetreBadgeHandler;
use App\Reservation\Entity\Reservation;
use App\Reservation\Enum\StatutReservation;
use App\Reservation\Service\ProjectionAccesReservationHandler;
use App\Tests\Acces\AccesApiTestCase;
use App\Tests\Acces\SnapshotDeltaTrait;
use Symfony\Component\Uid\Uuid;

/**
 * Réservation et badge du personnel : deux projections recalculées par leur module, que la borne
 * synchronisée en delta ne voyait pas avant le 04/10.
 *
 * Une réservation annulée gardait sa porte ouverte hors ligne ; un employé dont le créneau est annulé
 * gardait la sienne. Les tests passent par les handlers des modules (`ProjectionAccesReservationHandler`,
 * `RecalculFenetreBadgeHandler`), pas par une écriture du test sur le droit.
 */
final class SnapshotDeltaBookingStaffTest extends AccesApiTestCase
{
    use SnapshotDeltaTrait;

    // --- Réservation : déplacement du créneau, puis annulation ------------------------------------

    public function testReservationDeplaceePuisAnnuleeAtteintLeDelta(): void
    {
        $reservation = $this->createConfirmedReservation();
        /** @var ProjectionAccesReservationHandler $handler */
        $handler = static::getContainer()->get(ProjectionAccesReservationHandler::class);
        $projection = $handler->projeterSiApplicable($reservation);
        self::assertNotNull($projection);
        $droit = $this->findRight((string) $projection->getDroitAccesRef());

        /** @var AppairageHandler $appairage */
        $appairage = static::getContainer()->get(AppairageHandler::class);
        $identifiant = 'RESA-' . substr((string) Uuid::v4(), 0, 8);
        $appairage->appairer($identifiant, TypeSupport::Qr, $droit, ModeAppairage::Caisse, $this->snapshotEtablissementA());
        $reservationId = $reservation->getId();

        // Créneau déplacé, re-projection (l.81-86) : la nouvelle fenêtre doit atteindre la borne.
        $curseur = $this->snapshotCursor();
        $em = $this->snapshotEm();
        $reservation = $em->getRepository(Reservation::class)->find($reservationId);
        $nouveauDebut = new \DateTimeImmutable('2026-12-02T14:00:00+00:00');
        $reservation->getCreneau()->setDebut($nouveauDebut)->setFin($nouveauDebut->modify('+60 minutes'));
        $em->flush();
        static::getContainer()->get(ProjectionAccesReservationHandler::class)->projeterSiApplicable($reservation);
        $entree = $this->assertInDelta($curseur, $identifiant, 'ProjectionAccesReservationHandler::projeterSiApplicable');
        self::assertSame($nouveauDebut->format(\DATE_ATOM), (new \DateTimeImmutable($entree['validiteDebut']))->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM));

        // Annulation (l.128) : la borne doit fermer.
        $curseur = $this->snapshotCursor();
        $em = $this->snapshotEm();
        $reservation = $em->getRepository(Reservation::class)->find($reservationId);
        $reservation->setStatut(StatutReservation::AnnuleeLibre);
        $em->flush();
        static::getContainer()->get(ProjectionAccesReservationHandler::class)->revoquerSiProjete($reservation);
        self::assertTrue($this->assertInDelta($curseur, $identifiant, 'ProjectionAccesReservationHandler::revoquerSiProjete')['revoque']);
    }

    // --- Personnel : fin de créneau, passage en permanent -----------------------------------------

    public function testBadgePersonnelFinDeCreneauAtteintLeDelta(): void
    {
        [$badgeId, $creneauId, $identifiant] = $this->emettreBadgeAvecCreneau();
        $curseur = $this->snapshotCursor();

        // Le créneau est annulé : plus aucun créneau, la fenêtre part dans le passé (l.73).
        $em = $this->snapshotEm();
        $em->getRepository(CreneauTravail::class)->find($creneauId)->setStatut(StatutCreneauTravail::Annule);
        $em->flush();
        static::getContainer()->get(RecalculFenetreBadgeHandler::class)->recalculer($em->getRepository(BadgeStaff::class)->find($badgeId));

        $entree = $this->assertInDelta($curseur, $identifiant, 'RecalculFenetreBadgeHandler::recalculer (hors créneau)');
        self::assertStringStartsWith('1970-01-01', (string) $entree['validiteFin'], 'Hors créneau : la borne doit refuser le badge.');

        // Passage en permanent (l.55) : la fenêtre se lève.
        $curseur = $this->snapshotCursor();
        $em = $this->snapshotEm();
        $em->getRepository(PorteeAccesEmploye::class)->findOneBy(['badgeStaff' => $em->getRepository(BadgeStaff::class)->find($badgeId)])->setModeHoraire(ModeHoraireBadge::Permanent);
        $em->flush();
        static::getContainer()->get(RecalculFenetreBadgeHandler::class)->recalculer($em->getRepository(BadgeStaff::class)->find($badgeId));
        self::assertNull($this->assertInDelta($curseur, $identifiant, 'RecalculFenetreBadgeHandler::recalculer (permanent)')['validiteFin']);
    }

    // --- Échafaudages ---------------------------------------------------------------------------

    private function emettreBadgeAvecCreneau(): array
    {
        $em = $this->snapshotEm();
        $etab = $this->snapshotEtablissementA();
        $employe = (new Employe())->setNom('Delta')->setPrenom('Badge')->setPoste('Agent')
            ->setTypeContrat(TypeContrat::Cdi)->setDateEntree(new \DateTimeImmutable('2024-01-01'));
        $em->persist($employe);

        $droit = (new DroitAcces())->setSourceType(TypeDroitAcces::Personnel)->setEtablissement($etab)
            ->setMargeAvanceDefaut(15)->setMargeRetardDefaut(15);
        $em->persist($droit);
        $identifiant = 'BADGE-DELTA-' . substr((string) Uuid::v4(), 0, 8);
        $appairage = static::getContainer()->get(AppairageHandler::class)
            ->appairer($identifiant, TypeSupport::Rfid, $droit, ModeAppairage::Caisse, $etab);

        $badge = (new BadgeStaff())->setEmploye($employe)->setEtablissement($etab)
            ->setSupport($appairage->getSupport())->setDroitAcces($droit)->setStatut(StatutBadgeStaff::Actif);
        $em->persist($badge);
        $portee = (new PorteeAccesEmploye())->setBadgeStaff($badge)->setModeHoraire(ModeHoraireBadge::ShiftsUniquement)->setMargeAvantApres(15);
        $em->persist($portee);

        $debut = \DateTimeImmutable::createFromFormat('U', (string) (new \DateTimeImmutable('+2 hours'))->getTimestamp());
        $fin = \DateTimeImmutable::createFromFormat('U', (string) (new \DateTimeImmutable('+4 hours'))->getTimestamp());
        $creneau = (new CreneauTravail())->setEtablissement($etab)->setLibellePoste('Shift delta')
            ->setDebut($debut)->setFin($fin)->setEffectifRequis(1)->setStatut(StatutCreneauTravail::Confirme);
        $em->persist($creneau);
        $em->persist((new AffectationTravail())->setCreneauTravail($creneau)->setEmploye($employe)->setStatut(StatutAffectationTravail::Confirmee));
        $em->flush();

        static::getContainer()->get(RecalculFenetreBadgeHandler::class)->recalculer($badge);
        self::assertNotNull($this->findRight((string) $droit->getId())->getFenetreFin(), 'témoin : la fenêtre suit le créneau avant la fin de créneau');

        return [$badge->getId(), $creneau->getId(), $identifiant];
    }
}
