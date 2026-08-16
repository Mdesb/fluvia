<?php

declare(strict_types=1);

namespace App\Tests\Reservation\Api;

use App\Reservation\DataFixtures\ReservationFixtures;
use App\Reservation\Entity\Creneau;
use App\Reservation\Entity\Ressource;
use App\Reservation\Service\RecurrenceReportHandler;
use App\Tests\Reservation\ReservationApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/** Récurrence & report automatique (RG-M5-07/11, CA-6/CA-7). */
final class RecurrenceTest extends ReservationApiTestCase
{
    public function testCa6ModificationUneSeuleOccurrence(): void
    {
        [$client, $entete] = $this->gestionnaireSurA();
        $client->disableReboot();
        $terrain = '/api/reservation_ressources/' . $this->idRessource(ReservationFixtures::RESSOURCE_TERRAIN_LIBELLE);

        $client->request('POST', '/api/reservation/creneaux', $entete + [
            'json' => [
                'ressource' => $terrain,
                'debut' => '2026-09-14T10:00:00+00:00', // lundi
                'fin' => '2026-09-14T11:00:00+00:00',
                'capacite' => 4,
                'recurrence' => ['motif' => 'hebdomadaire', 'finRecurrence' => '2026-09-28', 'joursSemaine' => [1]],
            ],
        ]);
        self::assertResponseIsSuccessful();
        $premier = $client->getResponse()->toArray();
        self::assertNotNull($premier['recurrence'] ?? null);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $occurrences = $em->getRepository(Creneau::class)->findBy(['recurrence' => $em->getRepository(\App\Reservation\Entity\Recurrence::class)->find($this->extraireId($premier['recurrence']))]);
        self::assertGreaterThanOrEqual(3, \count($occurrences), 'RG-M5-07 : les occurrences hebdomadaires du 14/09 au 28/09 sont générées.');

        $deuxieme = null;
        foreach ($occurrences as $occurrence) {
            if ((string) $occurrence->getId() !== $premier['id']) {
                $deuxieme = $occurrence;
                break;
            }
        }
        self::assertNotNull($deuxieme);

        // Modifie uniquement la 2ᵉ occurrence (nouvel horaire).
        $client->request('PATCH', '/api/reservation/creneaux/' . $deuxieme->getId(), $this->entetePatch($entete) + [
            'json' => ['debut' => '2026-09-21T16:00:00+00:00', 'fin' => '2026-09-21T17:00:00+00:00'],
        ]);
        self::assertResponseIsSuccessful();
        $modifie = $client->getResponse()->toArray();
        self::assertTrue($modifie['occurrenceModifiee']);

        // La 1ʳᵉ occurrence reste intacte.
        $client->request('GET', '/api/reservation_creneaus/' . $premier['id'], $entete);
        self::assertResponseIsSuccessful();
        $premierApres = $client->getResponse()->toArray();
        self::assertStringStartsWith('2026-09-14T10:00:00', $premierApres['debut'], 'CA-6 : la série et les occurrences non modifiées restent intactes.');
        self::assertFalse($premierApres['occurrenceModifiee']);
    }

    public function testCa7ReportAutoSurRessourceEquivalente(): void
    {
        [$client, $entete] = $this->adminSurA();
        // Le kernel reboote entre chaque requête HTTP (par défaut) : évite de conserver une référence
        // EntityManager antérieure à une requête pour manipuler des entités après coup.
        $client->disableReboot();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $terrainOriginal = $this->entite(Ressource::class, ['libelle' => ReservationFixtures::RESSOURCE_TERRAIN_LIBELLE]);
        $terrainAlternatif = (new Ressource())->setEtablissement($terrainOriginal->getEtablissement())
            ->setCodeType('terrain')->setLibelle('Terrain padel n°2 (alternatif)')->setCapacitePropre(4);
        $em->persist($terrainAlternatif);
        $em->flush();

        $client->request('POST', '/api/reservation/creneaux', $entete + [
            'json' => [
                'ressource' => '/api/reservation_ressources/' . $terrainOriginal->getId(),
                'debut' => '2026-09-15T09:00:00+00:00',
                'fin' => '2026-09-15T10:00:00+00:00',
                'capacite' => 4,
            ],
        ]);
        self::assertResponseIsSuccessful();
        $idCreneau = $client->getResponse()->toArray()['id'];

        /** @var RecurrenceReportHandler $handler */
        $handler = static::getContainer()->get(RecurrenceReportHandler::class);
        $creneau = $em->getRepository(Creneau::class)->find($idCreneau);
        $reporte = $handler->tenterReport($creneau);

        self::assertTrue($reporte, 'CA-7 : report automatique réussi sur une ressource alternative équivalente.');
        $em->clear();
        $creneauApres = $em->getRepository(Creneau::class)->find($idCreneau);
        self::assertSame((string) $terrainAlternatif->getId(), (string) $creneauApres->getRessource()->getId());
        self::assertFalse($creneauApres->isEnAttenteArbitrage());
    }

    public function testCa7BasculeValidationManuelleSiAucuneAlternative(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        $client->request('POST', '/api/reservation/creneaux', $entete + [
            'json' => [
                'ressource' => '/api/reservation_ressources/' . $this->idRessource(ReservationFixtures::RESSOURCE_TERRAIN_LIBELLE),
                'debut' => '2026-09-16T09:00:00+00:00',
                'fin' => '2026-09-16T10:00:00+00:00',
                'capacite' => 4,
            ],
        ]);
        self::assertResponseIsSuccessful();
        $idCreneau = $client->getResponse()->toArray()['id'];

        /** @var RecurrenceReportHandler $handler */
        $handler = static::getContainer()->get(RecurrenceReportHandler::class);
        $creneau = $em->getRepository(Creneau::class)->find($idCreneau);
        $reporte = $handler->tenterReport($creneau);

        self::assertFalse($reporte, 'CA-7 : aucune ressource équivalente disponible -> validation manuelle.');
        $em->clear();
        $creneauApres = $em->getRepository(Creneau::class)->find($idCreneau);
        self::assertTrue($creneauApres->isEnAttenteArbitrage());

        // Arbitrage manuel : confirme le créneau tel quel.
        $client->request('POST', '/api/reservation/creneaux/' . $idCreneau . '/arbitrer', $entete + ['json' => []]);
        self::assertResponseIsSuccessful();
        self::assertFalse($client->getResponse()->toArray()['enAttenteArbitrage']);
    }

    private function extraireId(mixed $reference): string
    {
        if (\is_array($reference)) {
            return (string) ($reference['id'] ?? '');
        }
        $segment = (string) $reference;

        return str_contains($segment, '/') ? basename($segment) : $segment;
    }
}
