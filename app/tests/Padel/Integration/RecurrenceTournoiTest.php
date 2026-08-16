<?php

declare(strict_types=1);

namespace App\Tests\Padel\Integration;

use App\Padel\Entity\TerrainPadel;
use App\Reservation\Entity\Creneau;
use App\Reservation\Entity\Ressource;
use App\Reservation\Service\RecurrenceReportHandler;
use App\Tests\Padel\PadelApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Articulation récurrence ↔ tournoi (US-PADEL-07, RG-PADEL-07, décision actée « Récurrence vs
 * tournoi », CA-8). Le padel ne redéfinit **aucun** comportement : ce test prouve que le mécanisme
 * générique `RecurrenceReportHandler` (socle Réservation) fonctionne tel quel sur une ressource
 * `terrain_padel` (report automatique, ou validation manuelle à défaut d'alternative) — aucun code
 * Padel supplémentaire (T10 du plan).
 */
final class RecurrenceTournoiTest extends PadelApiTestCase
{
    public function testCa8ReportAutomatiqueSurAutreTerrainDisponible(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        $terrain1 = $this->entite(TerrainPadel::class, []);
        $ressource1 = $terrain1->getRessource();

        // 2ᵉ terrain padel disponible (alternative de report).
        $ressource2 = (new Ressource())->setEtablissement($ressource1->getEtablissement())
            ->setCodeType('terrain_padel')->setLibelle('Terrain padel n°2 (alternatif)')->setCapacitePropre(4);
        $em->persist($ressource2);
        $em->flush();

        // Réservation récurrente hebdomadaire sur le terrain 1 (RG-PADEL-07, générique socle).
        $client->request('POST', '/api/reservation/creneaux', $entete + [
            'json' => [
                'ressource' => '/api/reservation_ressources/' . $ressource1->getId(),
                'debut' => '2026-09-14T18:00:00+00:00',
                'fin' => '2026-09-14T19:30:00+00:00',
                'capacite' => 4,
                'recurrence' => ['motif' => 'hebdomadaire', 'finRecurrence' => '2026-10-05', 'joursSemaine' => [1]],
            ],
        ]);
        self::assertResponseIsSuccessful();
        $idCreneau = $client->getResponse()->toArray()['id'];

        // Le club bloque le terrain 1 pour un tournoi sur ce créneau : déclenche le report automatique
        // générique (§4.6, RG-PADEL-07) — appel direct au service socle (aucune redéfinition Padel).
        /** @var RecurrenceReportHandler $handler */
        $handler = static::getContainer()->get(RecurrenceReportHandler::class);
        $creneau = $em->getRepository(Creneau::class)->find($idCreneau);
        $reporte = $handler->tenterReport($creneau);

        self::assertTrue($reporte, 'CA-8 : report automatique sur le terrain padel alternatif disponible.');
        $em->clear();
        $creneauApres = $em->getRepository(Creneau::class)->find($idCreneau);
        self::assertSame((string) $ressource2->getId(), (string) $creneauApres->getRessource()->getId());
        self::assertFalse($creneauApres->isEnAttenteArbitrage());
    }

    public function testCa8ValidationManuelleSiAucunTerrainEquivalent(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $terrain1 = $this->entite(TerrainPadel::class, []);
        $ressource1 = $terrain1->getRessource();

        $client->request('POST', '/api/reservation/creneaux', $entete + [
            'json' => [
                'ressource' => '/api/reservation_ressources/' . $ressource1->getId(),
                'debut' => '2026-09-14T18:00:00+00:00',
                'fin' => '2026-09-14T19:30:00+00:00',
                'capacite' => 4,
            ],
        ]);
        self::assertResponseIsSuccessful();
        $idCreneau = $client->getResponse()->toArray()['id'];

        /** @var RecurrenceReportHandler $handler */
        $handler = static::getContainer()->get(RecurrenceReportHandler::class);
        $creneau = $em->getRepository(Creneau::class)->find($idCreneau);
        $reporte = $handler->tenterReport($creneau);

        self::assertFalse($reporte, 'CA-8 : aucun terrain équivalent disponible -> validation manuelle demandée.');
        $em->clear();
        $creneauApres = $em->getRepository(Creneau::class)->find($idCreneau);
        self::assertTrue($creneauApres->isEnAttenteArbitrage(), 'CA-8 : occurrence en attente jusqu\'à décision.');
    }
}
