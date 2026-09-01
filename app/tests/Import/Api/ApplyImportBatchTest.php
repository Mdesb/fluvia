<?php

declare(strict_types=1);

namespace App\Tests\Import\Api;

use App\Crm\Entity\Client as CrmClient;
use App\Import\Entity\ImportBatch;
use App\Import\Enum\ImportBatchStatus;
use App\Tests\Import\ImportApiTestCase;

/**
 * `POST /imports/{id}/appliquer` (plan-import-i1.md §0.2/§0.5/§0.9) — écriture en une transaction,
 * upsert exact par `externalRef` (D100), idempotence de fichier au sens de l'application (§0.9).
 */
final class ApplyImportBatchTest extends ImportApiTestCase
{
    public function testApplicationEcritToutOuRienDansUneTransaction(): void
    {
        [$client, $entete] = $this->adminSurA();
        $import = $this->deposerImport($client, $entete, $this->csvClientsValides(3));
        self::assertSame('validated', $import['status']);

        $reponse = $client->request('POST', '/api/imports/' . $import['id'] . '/appliquer', $entete)->toArray();

        self::assertSame('applied', $reponse['status']);
        self::assertSame(3, $this->compterClients(), 'Exactement rowCount créations (toutes nouvelles, I1).');
    }

    public function testApplicationRefuseeSiStatutNestPasValidated(): void
    {
        [$client, $entete] = $this->adminSurA();
        $import = $this->deposerImport($client, $entete, $this->csvClientsValides(2));
        $client->request('POST', '/api/imports/' . $import['id'] . '/appliquer', $entete);

        $seconde = $client->request('POST', '/api/imports/' . $import['id'] . '/appliquer', $entete);

        self::assertSame(409, $seconde->getStatusCode());
        self::assertSame(2, $this->compterClients(), 'Pas de seconde écriture.');
    }

    public function testApplicationSurLotRejeteRefuse409(): void
    {
        [$client, $entete] = $this->adminSurA();
        $csvInvalide = "externalRef;type;nom\nEXT-1;physique;\n";
        $rejete = $this->deposerImport($client, $entete, $csvInvalide, 'customers', 'invalide.csv');
        self::assertSame('rejected', $rejete['status']);

        $reponse = $client->request('POST', '/api/imports/' . $rejete['id'] . '/appliquer', $entete);

        self::assertSame(409, $reponse->getStatusCode());
    }

    public function testMemeContentHashDejaAppliqueRefuse409(): void
    {
        [$client, $entete] = $this->adminSurA();
        $csv = $this->csvClientsValides(2);

        $import1 = $this->deposerImport($client, $entete, $csv, 'customers', 'a.csv');
        $import2 = $this->deposerImport($client, $entete, $csv, 'customers', 'b.csv');
        self::assertSame($import1['contentHash'], $import2['contentHash']);

        $client->request('POST', '/api/imports/' . $import1['id'] . '/appliquer', $entete);
        $reponse = $client->request('POST', '/api/imports/' . $import2['id'] . '/appliquer', $entete);

        self::assertSame(409, $reponse->getStatusCode());
        self::assertSame(
            1,
            (int) $this->em()->getRepository(ImportBatch::class)->count(['status' => ImportBatchStatus::Applied]),
            'Le premier lot reste seul « applied » (§0.9).',
        );
    }

    public function testUpsertExternalRefConnuMetAJourSansDupliquer(): void
    {
        [$client, $entete] = $this->adminSurA();

        $premier = $this->deposerImport(
            $client,
            $entete,
            "externalRef;type;nom;email\nEXT-1;physique;Dupont;jean@old.fr\n",
            'customers',
            'v1.csv',
        );
        $client->request('POST', '/api/imports/' . $premier['id'] . '/appliquer', $entete);
        self::assertSame(1, $this->compterClients());

        $clientCree = $this->entite(CrmClient::class, ['email' => 'jean@old.fr']);
        $lotOrigine = $clientCree->getImportBatchRef();
        self::assertNotNull($lotOrigine);

        $corrige = $this->deposerImport(
            $client,
            $entete,
            "externalRef;type;nom;email\nEXT-1;physique;Dupont;jean@new.fr\n",
            'customers',
            'v2-corrige.csv',
        );
        $client->request('POST', '/api/imports/' . $corrige['id'] . '/appliquer', $entete);

        self::assertSame(1, $this->compterClients(), 'Aucun second Client créé (upsert par externalRef, D100).');
        $clientMaj = $this->entite(CrmClient::class, ['email' => 'jean@new.fr']);
        self::assertSame(
            (string) $lotOrigine,
            (string) $clientMaj->getImportBatchRef(),
            'importBatchRef reste celui du PREMIER lot (§0.6) : condition de l\'annulation exacte.',
        );
    }
}
