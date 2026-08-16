<?php

declare(strict_types=1);

namespace App\Tests\Padel\Api;

use App\Tests\Padel\PadelApiTestCase;

/** Réservation de terrain à l'heure (US-PADEL-01, RG-PADEL-01/02, CA-1/CA-2). */
final class ReservationTerrainTest extends PadelApiTestCase
{
    public function testCa1TarifAutomatiquePleineNonMembre(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $idTerrain = $this->idTerrain();
        $idOrganisateur = $this->idJoueur(3);
        $debut = $this->prochainLundi(19, 0);

        $client->request('POST', '/api/padel/terrains/' . $idTerrain . '/reservations', $entete + [
            'json' => [
                'debut' => $debut->format(DATE_ATOM),
                'dureeMinutes' => 90,
                'organisateur' => '/api/beneficiaires/' . $idOrganisateur,
            ],
        ]);
        self::assertResponseIsSuccessful();
        $donnees = $client->getResponse()->toArray();
        self::assertIsString($donnees['reservation']);
        $idReservation = basename((string) $donnees['reservation']);

        $client->request('GET', '/api/reservations/' . $idReservation, $entete);
        self::assertResponseIsSuccessful();
        $reservation = $client->getResponse()->toArray();

        // Le tarif est résolu automatiquement (plage pleine × non-membre × 90min = 38.00€, fixture).
        self::assertSame('38.00', $reservation['montantDu'] ?? null, 'CA-1 : tarif pleine/non-membre attendu, sans saisie manuelle.');
        self::assertCount(1, $reservation['participants'] ?? [], 'CA-1 : un seul joueur inscrit (organisateur).');
    }

    public function testCa2ChevauchementRefuse(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $idTerrain = $this->idTerrain();
        $idOrganisateur = $this->idJoueur(3);
        $debut = $this->prochainLundi(19, 0);

        $client->request('POST', '/api/padel/terrains/' . $idTerrain . '/reservations', $entete + [
            'json' => [
                'debut' => $debut->format(DATE_ATOM),
                'dureeMinutes' => 90,
                'organisateur' => '/api/beneficiaires/' . $idOrganisateur,
            ],
        ]);
        self::assertResponseIsSuccessful();

        // Second joueur tente une fenêtre chevauchante (19h30-21h) sur le même terrain.
        $idAutreOrganisateur = $this->idJoueur(4);
        $client->request('POST', '/api/padel/terrains/' . $idTerrain . '/reservations', $entete + [
            'json' => [
                'debut' => $debut->modify('+30 minutes')->format(DATE_ATOM),
                'dureeMinutes' => 90,
                'organisateur' => '/api/beneficiaires/' . $idAutreOrganisateur,
            ],
        ]);
        self::assertResponseStatusCodeSame(409, 'CA-2 : chevauchement refusé.');
    }

    private function prochainLundi(int $heure, int $minute): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('next monday'))->setTime($heure, $minute);
    }
}
