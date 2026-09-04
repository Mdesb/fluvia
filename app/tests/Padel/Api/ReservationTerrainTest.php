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

    /**
     * ⚠ TOUTE PLAGE HORAIRE DÉCLARÉE DOIT ÊTRE TARIFÉE — 23h30 était le trou.
     *
     * La fixture déclare quatre plages et n'en tarifait que trois : `plageCreuse2` (23h–minuit en
     * semaine) n'avait aucune ligne de grille. Une réservation à cette heure-là partait en 422
     * « Aucun tarif paramétré pour ce terrain », alors que le terrain est annoncé réservable.
     *
     * ⚠ ET CE DÉFAUT NE SE VOYAIT QU'UNE HEURE PAR JOUR. Les tests de confirmation réservent à
     * `maintenant + 3 heures` — ils le doivent, leur sujet est « à moins de 24 heures » — donc ils
     * n'atteignaient 23h que si la suite y arrivait entre 20h et 21h UTC. Le 04/09 elle y est
     * arrivée à 20h47 : six tests rouges d'un coup, sur un trou aussi vieux que la fixture.
     *
     * Ce témoin-ci réserve à une heure FIXE. Il tombe dans le trou à chaque exécution, pas une
     * fois sur vingt-quatre — c'est toute la différence entre un témoin et un hasard.
     */
    public function testUneReservationEnFinDeSoireeEstTarifee(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $client->request('POST', '/api/padel/terrains/' . $this->idTerrain() . '/reservations', $entete + [
            'json' => [
                'debut' => $this->prochainLundi(23, 30)->format(DATE_ATOM),
                'dureeMinutes' => 60,
                'organisateur' => '/api/beneficiaires/' . $this->idJoueur(4),
            ],
        ]);
        self::assertResponseIsSuccessful();

        $idReservation = basename((string) $client->getResponse()->toArray()['reservation']);
        $client->request('GET', '/api/reservations/' . $idReservation, $entete);
        self::assertResponseIsSuccessful();

        // Tarif creuse × non-membre × 60 min = 20,00 € (fixture). On vérifie le MONTANT, pas
        // seulement le succès : une réservation acceptée à 0,00 € serait un défaut plus discret.
        self::assertSame(
            '20.00',
            $client->getResponse()->toArray()['montantDu'] ?? null,
            'La plage creuse de fin de soirée doit être tarifée comme celle du matin.',
        );
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
