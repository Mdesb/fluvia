<?php

declare(strict_types=1);

namespace App\Tests\Reporting\Api;

use App\Tests\Reporting\ReportingApiTestCase;

/**
 * Référentiel `Indicateur` (cas limite spec §7) : jamais supprimable, seule
 * `Patch(actif=false)` disponible — aucune opération `Delete` exposée.
 */
final class ReferentielTest extends ReportingApiTestCase
{
    public function testNeufIndicateursSontCharges(): void
    {
        [$client, $entete] = $this->authSite();

        $indicateurs = $client->request('GET', '/api/indicateurs', $entete)->toArray();
        self::assertGreaterThanOrEqual(9, $indicateurs['totalItems'] ?? \count($indicateurs['member'] ?? []));
    }

    public function testAucuneOperationDeleteExposeeSurIndicateur(): void
    {
        [$client, $entete] = $this->authAdmin();
        $idCa = $this->idIndicateur('CA');

        $client->request('DELETE', '/api/indicateurs/' . $idCa, $entete);

        self::assertContains($client->getResponse()->getStatusCode(), [404, 405], 'Delete non exposé sur Indicateur (cas limite §7 spec).');
    }

    public function testDesactivationDUnIndicateurViaPatch(): void
    {
        [$client, $entete] = $this->authAdmin();
        $idImpayes = $this->idIndicateur('IMPAYES');

        $reponse = $client->request('PATCH', '/api/indicateurs/' . $idImpayes, $entete + [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['actif' => false],
        ]);

        self::assertResponseIsSuccessful();
        self::assertFalse($reponse->toArray()['actif']);
    }
}
