<?php

declare(strict_types=1);

namespace App\Tests\Padel\Api;

use App\Padel\Entity\TerrainPadel;
use App\Tests\Padel\PadelApiTestCase;
use App\Tests\Reservation\ConcurrentSlotWriter;

/**
 * Une réservation de terrain padel pèse sur la jauge globale de la ressource (RG-M5-08).
 *
 * Le padel ne lit pas ce compteur, mais les annulations du socle le décrémentent : sans incrément à
 * la réservation, chaque aller-retour retirait une unité que personne n'avait posée, et le compteur
 * dérivait vers le bas — vers la surréservation, pour les chemins qui le lisent.
 */
final class TerrainOccupancyTest extends PadelApiTestCase
{
    use ConcurrentSlotWriter;

    public function testATerrainBookingCountsOnTheResourceGauge(): void
    {
        [$client, $entete] = $this->gestionnaireSurA();
        $client->disableReboot();

        $terrain = $this->entite(TerrainPadel::class, []);
        \assert($terrain instanceof TerrainPadel);
        $ressource = $terrain->getRessource();
        self::assertNotNull($ressource, 'Terrain padel sans ressource socle (fixture).');
        $idRessource = (string) $ressource->getId();
        $avant = $this->occupationInDatabase($idRessource);

        $debut = (new \DateTimeImmutable('+21 days'))->setTime(9, 0);
        $client->request('POST', '/api/padel/terrains/' . $this->idTerrain() . '/reservations', $entete + [
            'json' => [
                'debut' => $debut->format(\DATE_ATOM),
                'dureeMinutes' => 60,
                'organisateur' => '/api/beneficiaires/' . $this->idJoueur(1),
            ],
        ]);
        self::assertResponseIsSuccessful('Réservation de terrain : ' . $client->getResponse()->getContent(false));

        self::assertSame(
            $avant + 1,
            $this->occupationInDatabase($idRessource),
            'La place tenue par la réservation de terrain compte sur la jauge globale.',
        );
    }
}
