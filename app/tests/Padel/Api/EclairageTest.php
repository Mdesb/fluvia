<?php

declare(strict_types=1);

namespace App\Tests\Padel\Api;

use App\Padel\Adapter\SimulateurEclairageAdapter;
use App\Padel\Command\CommanderEclairageCommand;
use App\Padel\Entity\RelaisEclairageTerrain;
use App\Tests\Padel\PadelApiTestCase;

/** Éclairage automatique du terrain (US-PADEL-10, RG-PADEL-05, CA-11). */
final class EclairageTest extends PadelApiTestCase
{
    public function testCa11AllumageEtExtinctionAutomatiquesSurFenetreReservee(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $idReservation = $this->reserverTerrain($client, $entete);
        $debut = (new \DateTimeImmutable('next monday'))->setTime(19, 0);

        /** @var CommanderEclairageCommand $commande */
        $commande = static::getContainer()->get(CommanderEclairageCommand::class);

        $traitees = $commande->commander($debut->modify('+1 minute'));
        self::assertSame(1, $traitees, 'CA-11 : allumage déclenché à l\'heure de début.');

        $client->request('GET', '/api/padel_evenement_eclairages', $entete);
        self::assertResponseIsSuccessful();
        $evenements = $client->getResponse()->toArray()['member'];
        self::assertCount(1, $evenements);
        self::assertSame('allumage', $evenements[0]['action']);
        self::assertSame('ok', $evenements[0]['statut']);

        $traitees = $commande->commander($debut->modify('+91 minutes'));
        self::assertSame(1, $traitees, 'CA-11 : extinction déclenchée à l\'heure de fin.');

        $client->request('GET', '/api/padel_evenement_eclairages', $entete);
        $evenements = $client->getResponse()->toArray()['member'];
        self::assertCount(2, $evenements);
        $actions = array_map(static fn (array $e) => $e['action'], $evenements);
        self::assertContains('extinction', $actions);
    }

    public function testCa11RepliManuelSiRelaisEnDefaut(): void
    {
        [$client, $entete] = $this->gestionnaireSurA();
        $client->disableReboot();

        $idTerrain = $this->idTerrain();

        // Force le relais en défaut (simulateur), simulant une panne matérielle.
        /** @var SimulateurEclairageAdapter $simulateur */
        $simulateur = static::getContainer()->get(SimulateurEclairageAdapter::class);
        $relais = $this->entite(RelaisEclairageTerrain::class, []);
        $simulateur->forcerDefaut($relais);

        // Sans motif -> refusé.
        $client->request('POST', '/api/padel/terrains/' . $idTerrain . '/eclairage/repli-manuel', $entete + [
            'json' => ['action' => 'allumage'],
        ]);
        self::assertResponseStatusCodeSame(422, 'CA-11 : motif requis pour un repli manuel.');

        $client->request('POST', '/api/padel/terrains/' . $idTerrain . '/eclairage/repli-manuel', $entete + [
            'json' => ['action' => 'allumage', 'motif' => 'Relais en panne, allumage manuel depuis le tableau.'],
        ]);
        self::assertResponseIsSuccessful();
        $evenement = $client->getResponse()->toArray();
        self::assertSame('echec_repli_manuel', $evenement['statut'] ?? null, 'CA-11 : incident tracé.');
        self::assertNotNull($evenement['motif'] ?? null);
        self::assertNotNull($evenement['operateur'] ?? null);
    }

    /**
     * @param array<string, mixed> $entete
     */
    private function reserverTerrain(object $client, array $entete): string
    {
        $idTerrain = $this->idTerrain();
        $debut = (new \DateTimeImmutable('next monday'))->setTime(19, 0);
        $client->request('POST', '/api/padel/terrains/' . $idTerrain . '/reservations', $entete + [
            'json' => [
                'debut' => $debut->format(DATE_ATOM),
                'dureeMinutes' => 90,
                'organisateur' => '/api/beneficiaires/' . $this->idJoueur(1),
            ],
        ]);
        self::assertResponseIsSuccessful();

        return basename((string) $client->getResponse()->toArray()['reservation']);
    }
}
