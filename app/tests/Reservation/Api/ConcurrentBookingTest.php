<?php

declare(strict_types=1);

namespace App\Tests\Reservation\Api;

use App\Reservation\DataFixtures\ReservationFixtures;
use App\Tests\Reservation\ConcurrentSlotWriter;
use App\Tests\Reservation\ReservationApiTestCase;

/**
 * Deux réservations simultanées sur la dernière place (RG-M5-01), par `ReserverProcessor`.
 *
 * Le contrôle de jauge lisait l'état validé, et la réservation s'écrivait plus loin, hors de toute
 * transaction : deux demandes simultanées lisaient « une place libre » et passaient toutes deux. La
 * concurrence est jouée par `ConcurrentSlotWriter`.
 *
 * Le premier cas échoue sur le code d'origine (201), et aussi quand une lecture de jauge précède le
 * verrou. Le second est sa paire : la garde ne doit pas refuser la place qui reste réellement.
 */
final class ConcurrentBookingTest extends ReservationApiTestCase
{
    use ConcurrentSlotWriter;

    public function testABookingWaitsForAConcurrentOneAndSeesTheSlotFull(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $idCreneau = $this->creerCreneau($client, $entete, 5);
        $idBeneficiaire = $this->idBeneficiairePayeur();

        $premiere = $this->reserver($client, $entete, $idCreneau, $idBeneficiaire, 1);
        self::assertResponseIsSuccessful();

        // Le concurrent porte la première réservation à 5 : le créneau est plein à sa validation.
        $concurrent = $this->holdSlotLock($idCreneau, $premiere['id'], 5);
        $debut = microtime(true);
        $reponse = $this->reserver($client, $entete, $idCreneau, $idBeneficiaire, 1);
        $attente = microtime(true) - $debut;
        $this->releaseSlotLock($concurrent);

        self::assertResponseStatusCodeSame(409, 'La place a été prise par la réservation concurrente : réponse ' . json_encode($reponse));
        self::assertStringContainsString('complet', $reponse['detail'] ?? '');
        $this->assertWaitedForTheConcurrentWrite($attente);
    }

    public function testAPlaceStillFreeAfterTheWaitIsBooked(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $idCreneau = $this->creerCreneau($client, $entete, 5);
        $idBeneficiaire = $this->idBeneficiairePayeur();

        $premiere = $this->reserver($client, $entete, $idCreneau, $idBeneficiaire, 1);
        self::assertResponseIsSuccessful();

        // Le concurrent porte la première réservation à 4 : il reste exactement une place.
        $concurrent = $this->holdSlotLock($idCreneau, $premiere['id'], 4);
        $debut = microtime(true);
        $reponse = $this->reserver($client, $entete, $idCreneau, $idBeneficiaire, 1);
        $attente = microtime(true) - $debut;
        $this->releaseSlotLock($concurrent);

        self::assertResponseIsSuccessful('La dernière place réellement libre doit être réservée : réponse ' . json_encode($reponse));
        $this->assertWaitedForTheConcurrentWrite($attente);

        // 5/5 : la suivante est refusée, sans concurrent cette fois.
        $this->reserver($client, $entete, $idCreneau, $idBeneficiaire, 1);
        self::assertResponseStatusCodeSame(409);
    }

    /** @return array<string, mixed> */
    private function reserver(object $client, array $entete, string $idCreneau, string $idBeneficiaire, int $quantite): array
    {
        $client->request('POST', '/api/reservation/reservations', $entete + [
            'json' => [
                'creneau' => '/api/reservation_creneaus/' . $idCreneau,
                'organisateur' => '/api/beneficiaires/' . $idBeneficiaire,
                'quantity' => $quantite,
            ],
        ]);

        return $client->getResponse()->toArray(false);
    }

    /** @param array<string, mixed> $entete */
    private function creerCreneau(object $client, array $entete, int $capacite): string
    {
        $client->request('POST', '/api/reservation/creneaux', $entete + [
            'json' => [
                'ressource' => '/api/reservation_ressources/' . $this->idRessource(ReservationFixtures::RESSOURCE_SALLE_LIBELLE),
                'activite' => '/api/reservation_activites/' . $this->idActivite(ReservationFixtures::ACTIVITE_GRATUITE_LIBELLE),
                'debut' => '2026-09-11T14:00:00+00:00',
                'fin' => '2026-09-11T15:00:00+00:00',
                'capacite' => $capacite,
            ],
        ]);
        self::assertResponseIsSuccessful();

        return $client->getResponse()->toArray()['id'];
    }
}
