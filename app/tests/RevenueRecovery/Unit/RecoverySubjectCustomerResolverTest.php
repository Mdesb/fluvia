<?php

declare(strict_types=1);

namespace App\Tests\RevenueRecovery\Unit;

use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Reservation\Entity\Reservation;
use App\RevenueRecovery\Service\RecoverySubjectCustomerResolver;
use App\Tests\RevenueRecovery\RevenueRecoveryApiTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * `RecoverySubjectCustomerResolver` (RG-RR-07, revue de cohérence — défense en profondeur, échec fermé,
 * même patron qu'`App\SmartFlow\Service\ReservationSlotReader::snapshotReservation()`) : la résolution
 * ne doit jamais rendre un client dont la `Reservation` référencée appartient à un autre établissement
 * que celui du `RecoveryCase` appelant.
 */
final class RecoverySubjectCustomerResolverTest extends RevenueRecoveryApiTestCase
{
    public function testReservationDeLetablissementAttenduResoutLeClient(): void
    {
        $etablissementA = $this->entity(Etablissement::class, ['nom' => SocleFixtures::ETAB_A_NOM]);
        $beneficiaire = $this->beneficiairePayeur();
        $reservation = $this->em()->getRepository(Reservation::class)->findOneBy(['organisateur' => $beneficiaire]);
        self::assertInstanceOf(Reservation::class, $reservation, 'Réservation de démonstration introuvable (fixtures Reservation).');

        $clientId = $this->resolver()->resolveCustomerId('Reservation', (string) $reservation->getId(), $etablissementA->getId());

        self::assertNotNull($clientId);
        self::assertSame((string) $beneficiaire->getClient()?->getId(), (string) $clientId);
    }

    /** RG-RR-07 : une réservation d'un autre établissement que celui attendu ne doit jamais résoudre un client (échec fermé). */
    public function testReservationDunAutreEtablissementNeResoutRien(): void
    {
        $etablissementB = $this->entity(Etablissement::class, ['nom' => SocleFixtures::ETAB_B_NOM]);
        $beneficiaire = $this->beneficiairePayeur();
        $reservation = $this->em()->getRepository(Reservation::class)->findOneBy(['organisateur' => $beneficiaire]);
        self::assertInstanceOf(Reservation::class, $reservation, 'Réservation de démonstration introuvable (fixtures Reservation).');
        self::assertFalse(
            $reservation->getEtablissement()?->getId()->equals($etablissementB->getId()) ?? true,
            'Préalable du test : la réservation de démonstration doit appartenir à A, pas B.',
        );

        $clientId = $this->resolver()->resolveCustomerId('Reservation', (string) $reservation->getId(), $etablissementB->getId());

        self::assertNull($clientId, 'RG-RR-07 : la réservation référencée n\'appartient pas à l\'établissement du dossier -> échec fermé.');
    }

    public function testReservationIntrouvableNeResoutRien(): void
    {
        $etablissementA = $this->entity(Etablissement::class, ['nom' => SocleFixtures::ETAB_A_NOM]);

        $clientId = $this->resolver()->resolveCustomerId('Reservation', (string) Uuid::v4(), $etablissementA->getId());

        self::assertNull($clientId);
    }

    private function resolver(): RecoverySubjectCustomerResolver
    {
        return static::getContainer()->get(RecoverySubjectCustomerResolver::class);
    }
}
