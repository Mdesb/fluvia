<?php

declare(strict_types=1);

namespace App\Tests\Group\Api;

use App\Crm\Entity\Beneficiaire;
use App\Group\DataFixtures\GroupFixtures;
use App\Tests\Group\GroupApiTestCase;
use App\Tests\Reservation\ConcurrentSlotWriter;

/**
 * Confirmation d'une réservation de groupe pendant une réservation concurrente du même créneau
 * (RG-M5-01 / RG-MUS-01).
 *
 * `ConfirmGroupBookingProcessor` contrôlait les places restantes puis posait la jauge, sans
 * transaction : une confirmation de groupe et une réservation simultanées débordaient le créneau
 * ensemble. La concurrence est jouée par `ConcurrentSlotWriter`.
 */
final class ConcurrentGroupConfirmationTest extends GroupApiTestCase
{
    use ConcurrentSlotWriter;

    public function testAGroupConfirmationWaitsForAConcurrentBookingAndSeesTooFewPlaces(): void
    {
        [$client, $entete] = $this->gestionnaireSurA();
        $client->disableReboot();
        [$idBooking, $idCreneau] = $this->assignedBookingOfTwo($client, $entete);

        // Il ne restera qu'une place pour un effectif de 2.
        $remplissage = $this->persistFillerReservation($idCreneau, $this->unBeneficiaire());
        $concurrent = $this->holdSlotLock($idCreneau, $remplissage, $this->fillerQuantityLeaving($idCreneau, 1));
        $debut = microtime(true);
        $client->request('POST', '/api/group/bookings/' . $idBooking . '/confirm', $entete + ['json' => []]);
        $attente = microtime(true) - $debut;
        $this->releaseSlotLock($concurrent);

        self::assertResponseStatusCodeSame(409, 'Les places ont été prises par la réservation concurrente.');
        self::assertStringContainsString('Jauge insuffisante', $client->getResponse()->toArray(false)['detail'] ?? '');
        $this->assertWaitedForTheConcurrentWrite($attente);
    }

    public function testAGroupConfirmationTakesThePlacesStillFreeAfterTheWait(): void
    {
        [$client, $entete] = $this->gestionnaireSurA();
        $client->disableReboot();
        [$idBooking, $idCreneau] = $this->assignedBookingOfTwo($client, $entete);

        // Il restera exactement les 2 places de l'effectif.
        $remplissage = $this->persistFillerReservation($idCreneau, $this->unBeneficiaire());
        $concurrent = $this->holdSlotLock($idCreneau, $remplissage, $this->fillerQuantityLeaving($idCreneau, 2));
        $debut = microtime(true);
        $client->request('POST', '/api/group/bookings/' . $idBooking . '/confirm', $entete + ['json' => []]);
        $attente = microtime(true) - $debut;
        $this->releaseSlotLock($concurrent);

        self::assertResponseIsSuccessful('Les places réellement libres doivent être confirmées.');
        self::assertSame('confirmed', $client->getResponse()->toArray()['status']);
        $this->assertWaitedForTheConcurrentWrite($attente);
    }

    /**
     * @param array<string, mixed> $entete
     *
     * @return array{0: string, 1: string} identifiants de la réservation de groupe et de son créneau
     */
    private function assignedBookingOfTwo(object $client, array $entete): array
    {
        $client->request('POST', '/api/group/bookings', $entete + [
            'json' => [
                'group' => '/api/participant_groups/' . $this->idGroupe(GroupFixtures::GROUPE_A_LABEL),
                'effectif' => 2,
                'accompagnateurs' => 0,
            ],
        ]);
        self::assertResponseIsSuccessful();
        $idBooking = $client->getResponse()->toArray()['id'];

        $idCreneau = $this->unCreneauDeA();
        $client->request('POST', '/api/group/bookings/' . $idBooking . '/assign', $entete + [
            'json' => ['creneau' => '/api/reservation_creneaux/' . $idCreneau],
        ]);
        self::assertResponseIsSuccessful();

        return [$idBooking, $idCreneau];
    }

    private function unBeneficiaire(): Beneficiaire
    {
        $beneficiaire = $this->entite(Beneficiaire::class, []);
        \assert($beneficiaire instanceof Beneficiaire);

        return $beneficiaire;
    }
}
