<?php

declare(strict_types=1);

namespace App\Tests\Import\Api;

use App\Crm\Entity\Client as CrmClient;
use App\Crm\Entity\Consentement;
use App\Tests\Import\ImportApiTestCase;

/**
 * `POST /imports/{id}/annuler` (plan-import-i1.md §0.6/§0.8, SPEC-REPRISE-INITIALE.md §5) — supprime
 * exactement ce que le lot a créé, refuse si une ligne a servi depuis.
 */
final class RevertImportBatchTest extends ImportApiTestCase
{
    public function testAnnulationSupprimeExactementCeQueLeLotACree(): void
    {
        [$client, $entete] = $this->adminSurA();

        $base = $this->deposerImport($client, $entete, "externalRef;type;nom\nEXT-X;physique;Existing\n", 'customers', 'base.csv');
        $client->request('POST', '/api/imports/' . $base['id'] . '/appliquer', $entete);
        self::assertSame(1, $this->compterClients());

        $csvLot2 = "externalRef;type;nom\n"
            . "EXT-X;physique;ExistingUpdated\n"
            . "EXT-A;physique;A\n"
            . "EXT-B;physique;B\n"
            . "EXT-C;physique;C\n";
        $lot2 = $this->deposerImport($client, $entete, $csvLot2, 'customers', 'lot2.csv');
        $client->request('POST', '/api/imports/' . $lot2['id'] . '/appliquer', $entete);
        self::assertSame(4, $this->compterClients(), '1 mis à jour + 3 créés.');

        $revert = $client->request('POST', '/api/imports/' . $lot2['id'] . '/annuler', $entete)->toArray();

        self::assertSame('reverted', $revert['status']);
        self::assertSame(1, $this->compterClients(), 'Seul le client mis à jour par ce lot (pas créé) doit rester (spec §5).');
        $restant = $this->entite(CrmClient::class, ['nom' => 'ExistingUpdated']);
        self::assertNotNull($restant->getId());
    }

    public function testAnnulationRefuseeSiUneLigneAServiDepuis(): void
    {
        [$client, $entete] = $this->adminSurA();

        $import = $this->deposerImport($client, $entete, "externalRef;type;nom\nEXT-1;physique;Dupont\n", 'customers', 'r.csv');
        $client->request('POST', '/api/imports/' . $import['id'] . '/appliquer', $entete);
        self::assertSame(1, $this->compterClients());

        $creeClient = $this->entite(CrmClient::class, ['nom' => 'Dupont']);
        // Fixture : une association Doctrine réelle qui « sert » le client créé (§0.8).
        $this->em()->persist((new Consentement())->setClient($creeClient));
        $this->em()->flush();

        $reponse = $client->request('POST', '/api/imports/' . $import['id'] . '/annuler', $entete);

        self::assertSame(409, $reponse->getStatusCode());
        self::assertSame(1, $this->compterClients(), 'Rien supprimé.');
    }

    public function testAnnulationRefuseeSiStatutNestPasApplied(): void
    {
        [$client, $entete] = $this->adminSurA();
        $import = $this->deposerImport($client, $entete, $this->csvClientsValides(1));
        self::assertSame('validated', $import['status']);

        $reponse = $client->request('POST', '/api/imports/' . $import['id'] . '/annuler', $entete);

        self::assertSame(409, $reponse->getStatusCode());
    }
}
