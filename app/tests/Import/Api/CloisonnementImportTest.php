<?php

declare(strict_types=1);

namespace App\Tests\Import\Api;

use App\Tests\Import\ImportApiTestCase;

/**
 * D8 (plan-import-i1.md §3) — `PerimetreImportExtension` (point 1, lecture) et revérification
 * explicite dans `ApplyImportBatchProcessor` (point 2).
 */
final class CloisonnementImportTest extends ImportApiTestCase
{
    public function testLotHorsPerimetreInvisibleEnLecture(): void
    {
        [$clientA, $enteteA] = $this->adminSurA();
        $import = $this->deposerImport($clientA, $enteteA, $this->csvClientsValides(1));

        [$clientB, $enteteB] = $this->adminSurB();

        $reponseItem = $clientB->request('GET', '/api/imports/' . $import['id'], $enteteB);
        self::assertSame(404, $reponseItem->getStatusCode(), 'Lot hors périmètre -> 404 (introuvable).');

        $collection = $clientB->request('GET', '/api/imports', $enteteB)->toArray();
        $ids = array_map(static fn (array $i): string => $i['id'] ?? '', $collection['member'] ?? []);
        self::assertNotContains($import['id'], $ids, 'Lot hors périmètre absent de la collection.');
    }

    public function testAppliquerSurLotDunAutrePerimetreRefuse404(): void
    {
        [$clientA, $enteteA] = $this->adminSurA();
        $import = $this->deposerImport($clientA, $enteteA, $this->csvClientsValides(1));

        [$clientB, $enteteB] = $this->adminSurB();
        $reponse = $clientB->request('POST', '/api/imports/' . $import['id'] . '/appliquer', $enteteB);

        self::assertSame(404, $reponse->getStatusCode());
        self::assertSame(0, $this->compterClients(), 'Rien écrit depuis un périmètre étranger.');
    }
}
