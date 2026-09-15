<?php

declare(strict_types=1);

namespace App\Tests\Reporting\Api;

use App\Tests\Reporting\ReportingApiTestCase;

/**
 * Référentiel `Indicateur` (cas limite spec §7) : jamais supprimable, seule
 * `Patch(actif=false)` disponible — aucune opération `Delete` exposée.
 *
 * ⚠ `AxeAnalytique` A ÉTÉ SUPPRIMÉ le 15/09 (arbitrage de Maxime, point n°2 de
 * `COORDINATION/A-REVOIR.md`) : six axes alimentés par les fixtures et consommés par rien — ni
 * écran, ni moteur d'agrégation, ni client d'API. L'assertion qui comptait « six axes chargés »
 * est retirée avec lui. Elle vérifiait qu'un référentiel se remplissait, pas qu'il servait.
 */
final class ReferentielTest extends ReportingApiTestCase
{
    public function testNeufIndicateursSontCharges(): void
    {
        [$client, $entete] = $this->authSite();

        $indicateurs = $client->request('GET', '/api/indicateurs', $entete)->toArray();
        self::assertGreaterThanOrEqual(9, $indicateurs['totalItems'] ?? \count($indicateurs['member'] ?? []));
    }

    /**
     * ⚠ LA ROUTE DES AXES NE DOIT PLUS EXISTER, et ce test le vérifie plutôt que de le supposer.
     *
     * Supprimer une entité sans vérifier que sa route a disparu laisse le cas où API Platform la
     * sert encore depuis un cache compilé : la suppression aurait l'air faite.
     */
    public function testLaRouteDesAxesAnalytiquesNexistePlus(): void
    {
        [$client, $entete] = $this->authSite();

        $client->request('GET', '/api/axe_analytiques', $entete);

        self::assertSame(404, $client->getResponse()->getStatusCode());
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
