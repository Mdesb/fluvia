<?php

declare(strict_types=1);

namespace App\Tests\Stock\Api;

use ApiPlatform\Symfony\Bundle\Test\Client;
use App\DataFixtures\SocleFixtures;
use App\Tests\Stock\StockApiTestCase;

/**
 * UNE COMMANDE D'ACHAT PREND SON NUMÉRO DANS LA SÉRIE DE SON ÉTABLISSEMENT.
 *
 * Mesuré le 08/10/2026 : `GenerateurNumeroAchat` comptait toutes les commandes d'achat de la
 * plateforme. La première commande d'un établissement portait le rang que lui laissaient les autres
 * clients : des trous dans sa série, et le volume d'achat d'un client lisible par un autre.
 */
final class PurchaseOrderNumberPerSiteTest extends StockApiTestCase
{
    public function testEachSiteSendsPurchaseOrderNumberOneOfItsOwnSeries(): void
    {
        [$client, $headersA, $siteA] = $this->adminSurA();
        $first = $this->sendOrder($client, $headersA, $siteA);

        $siteB = $this->idEtablissement(SocleFixtures::ETAB_B_NOM);
        $second = $this->sendOrder($client, $this->enteteSur($headersA, SocleFixtures::ETAB_B_NOM), $siteB);

        self::assertStringEndsWith('-00001', $second, 'la première commande du second établissement ouvre sa série');
        self::assertSame('CA-' . substr(strtoupper($siteA), 0, 8) . '-00001', $first);
        self::assertSame('CA-' . substr(strtoupper($siteB), 0, 8) . '-00001', $second);
    }

    /** Crée un fournisseur et une commande sur le site, l'envoie, et rend son numéro. */
    private function sendOrder(Client $client, array $headers, string $site): string
    {
        $siteIri = '/api/etablissements/' . $site;
        $supplier = $client->request('POST', '/api/stock_fournisseurs', $headers + [
            'json' => ['etablissement' => $siteIri, 'raisonSociale' => 'Grossiste ' . $site],
        ])->toArray();
        $order = $client->request('POST', '/api/stock_commande_achats', $headers + [
            'json' => ['etablissement' => $siteIri, 'fournisseur' => '/api/stock_fournisseurs/' . $supplier['id'], 'dateCommande' => '2026-03-01'],
        ])->toArray();

        $response = $client->request('POST', '/api/stock/commandes-achat/' . $order['id'] . '/envoyer', $headers + ['json' => []]);
        self::assertLessThan(300, $response->getStatusCode(), $response->getContent(false));

        return $response->toArray()['numero'];
    }
}
