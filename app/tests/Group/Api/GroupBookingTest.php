<?php

declare(strict_types=1);

namespace App\Tests\Group\Api;

use App\Group\DataFixtures\GroupFixtures;
use App\Tests\Group\GroupApiTestCase;

/**
 * Réservation de groupe : création (option), affectation à un créneau, confirmation, annulation — le
 * cycle de vie transverse (généralisation du dossier musée), et ses gardes de cloisonnement / d'état.
 */
final class GroupBookingTest extends GroupApiTestCase
{
    public function testCreerAffecterConfirmerAnnuler(): void
    {
        [$client, $entete] = $this->gestionnaireSurA();
        $idGroupeA = $this->idGroupe(GroupFixtures::GROUPE_A_LABEL);

        // Création : option par défaut.
        $client->request('POST', '/api/group/bookings', $entete + [
            'json' => [
                'group' => '/api/participant_groups/' . $idGroupeA,
                'effectif' => 28,
                'accompagnateurs' => 2,
            ],
        ]);
        self::assertResponseIsSuccessful();
        $booking = $client->getResponse()->toArray();
        self::assertSame('option', $booking['status']);
        $id = $booking['id'];

        // Affectation à un créneau de A → l'activité du créneau est reprise.
        $client->request('POST', '/api/group/bookings/' . $id . '/assign', $entete + [
            'json' => ['creneau' => '/api/reservation_creneaux/' . $this->unCreneauDeA()],
        ]);
        self::assertResponseIsSuccessful();
        $affecte = $client->getResponse()->toArray();
        self::assertNotNull($affecte['creneau'] ?? null, 'Le créneau doit être affecté.');
        self::assertNotNull($affecte['activite'] ?? null, 'L\'activité du créneau doit être reprise.');

        // Confirmation.
        $client->request('POST', '/api/group/bookings/' . $id . '/confirm', $entete + ['json' => []]);
        self::assertResponseIsSuccessful();
        self::assertSame('confirmed', $client->getResponse()->toArray()['status']);

        // Annulation.
        $client->request('POST', '/api/group/bookings/' . $id . '/cancel', $entete + ['json' => []]);
        self::assertResponseIsSuccessful();
        self::assertSame('cancelled', $client->getResponse()->toArray()['status']);

        // Une réservation annulée ne se ré-affecte pas.
        $client->request('POST', '/api/group/bookings/' . $id . '/assign', $entete + [
            'json' => ['creneau' => '/api/reservation_creneaux/' . $this->unCreneauDeA()],
        ]);
        self::assertResponseStatusCodeSame(422, 'Une réservation annulée ne peut plus être affectée.');
    }

    public function testCreationPourUnGroupeEtrangerRefusee(): void
    {
        [$client, $entete] = $this->gestionnaireSurA();
        $idGroupeB = $this->idGroupe(GroupFixtures::GROUPE_B_LABEL);

        $client->request('POST', '/api/group/bookings', $entete + [
            'json' => ['group' => '/api/participant_groups/' . $idGroupeB],
        ]);
        self::assertResponseStatusCodeSame(404, 'Réserver pour un groupe hors établissement actif est introuvable.');
    }
}
