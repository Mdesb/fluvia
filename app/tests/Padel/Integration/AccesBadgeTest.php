<?php

declare(strict_types=1);

namespace App\Tests\Padel\Integration;

use App\Reservation\Entity\ProjectionAccesReservation;
use App\Tests\Padel\PadelApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Badge d'accès sur la fenêtre réservée (RG-PADEL-05 volet accès, CA-12). Réutilise intégralement le
 * mécanisme générique `ProjectionAccesReservation` du socle L5/L3 (`TerrainPadel.ressource.ouvreAcces
 * = true` en fixture) — aucune redéfinition côté Padel.
 */
final class AccesBadgeTest extends PadelApiTestCase
{
    public function testCa12ProjectionAccesSurFenetreReservee(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $idTerrain = $this->idTerrain();
        $debut = (new \DateTimeImmutable('next monday'))->setTime(19, 0);
        $fin = $debut->modify('+90 minutes');

        $client->request('POST', '/api/padel/terrains/' . $idTerrain . '/reservations', $entete + [
            'json' => [
                'debut' => $debut->format(DATE_ATOM),
                'dureeMinutes' => 90,
                'organisateur' => '/api/beneficiaires/' . $this->idJoueur(1),
            ],
        ]);
        self::assertResponseIsSuccessful();
        $idReservation = basename((string) $client->getResponse()->toArray()['reservation']);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $projection = $em->getRepository(ProjectionAccesReservation::class)->findOneBy(['reservation' => $idReservation]);

        self::assertNotNull($projection, 'CA-12 : le badge est projeté sur la fenêtre réservée (ressource ouvreAcces=true).');
        self::assertSame($debut->format('Y-m-d H:i:s'), $projection->getFenetreDebut()->format('Y-m-d H:i:s'));
        self::assertSame($fin->format('Y-m-d H:i:s'), $projection->getFenetreFin()->format('Y-m-d H:i:s'));
    }
}
