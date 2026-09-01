<?php

declare(strict_types=1);

namespace App\Tests\Import\Api;

use App\Tests\Import\ImportApiTestCase;

/**
 * §0.9 du plan — un doublon de fichier est **détectable**, jamais bloqué à la validation : deux dépôts
 * identiques coexistent en `validated`, visibles via le filtre `?contentHash=…`.
 */
final class ImportBatchContentHashTest extends ImportApiTestCase
{
    public function testFiltreContentHashPermetDeDetecterUnDoublonSansBloquer(): void
    {
        [$client, $entete] = $this->adminSurA();
        $csv = $this->csvClientsValides(2);

        $import1 = $this->deposerImport($client, $entete, $csv, 'customers', 'a.csv');
        $import2 = $this->deposerImport($client, $entete, $csv, 'customers', 'b.csv');

        self::assertSame('validated', $import1['status']);
        self::assertSame('validated', $import2['status']);
        self::assertNotEmpty($import1['contentHash']);
        self::assertSame($import1['contentHash'], $import2['contentHash']);

        $collection = $client->request('GET', '/api/imports', $entete + [
            'query' => ['contentHash' => $import1['contentHash']],
        ])->toArray();

        self::assertCount(2, $collection['member'], 'Le doublon est détectable sans avoir été bloqué.');
    }
}
