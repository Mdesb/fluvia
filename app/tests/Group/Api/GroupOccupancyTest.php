<?php

declare(strict_types=1);

namespace App\Tests\Group\Api;

use App\Group\DataFixtures\GroupFixtures;
use App\Reservation\Entity\Creneau;
use App\Tests\Group\GroupApiTestCase;
use App\Tests\Reservation\ConcurrentSlotWriter;

/**
 * Une confirmation de groupe pèse sur la jauge globale de la ressource (RG-M5-08), et son annulation
 * la rend.
 *
 * La confirmation posait ses réservations sans incrémenter `Ressource.occupationCourante`, alors que
 * l'annulation, elle, décrémente : le compteur dérivait vers le bas à chaque aller-retour.
 */
final class GroupOccupancyTest extends GroupApiTestCase
{
    use ConcurrentSlotWriter;

    public function testAGroupConfirmationCountsItsHeadcountAndCancellationGivesItBack(): void
    {
        [$client, $entete] = $this->gestionnaireSurA();
        $client->disableReboot();
        $idCreneau = $this->unCreneauDeA();
        $ressource = $this->entite(Creneau::class, ['id' => $idCreneau])->getRessource();
        self::assertNotNull($ressource);
        $idRessource = (string) $ressource->getId();
        $avant = $this->occupationInDatabase($idRessource);

        $client->request('POST', '/api/group/bookings', $entete + [
            'json' => [
                'group' => '/api/participant_groups/' . $this->idGroupe(GroupFixtures::GROUPE_A_LABEL),
                'effectif' => 2,
                'accompagnateurs' => 0,
            ],
        ]);
        self::assertResponseIsSuccessful();
        $id = $client->getResponse()->toArray()['id'];

        $client->request('POST', '/api/group/bookings/' . $id . '/assign', $entete + [
            'json' => ['creneau' => '/api/reservation_creneaux/' . $idCreneau],
        ]);
        self::assertResponseIsSuccessful();

        $client->request('POST', '/api/group/bookings/' . $id . '/confirm', $entete + ['json' => []]);
        self::assertResponseIsSuccessful();
        self::assertSame($avant + 2, $this->occupationInDatabase($idRessource), 'Les deux entrées du groupe comptent sur la jauge globale.');

        $client->request('POST', '/api/group/bookings/' . $id . '/cancel', $entete + ['json' => []]);
        self::assertResponseIsSuccessful();
        self::assertSame($avant, $this->occupationInDatabase($idRessource), 'L\'annulation rend exactement ce que la confirmation avait pris.');
    }
}
