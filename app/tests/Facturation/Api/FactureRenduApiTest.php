<?php

declare(strict_types=1);

namespace App\Tests\Facturation\Api;

use App\Tests\Facturation\FacturationApiTestCase;

/**
 * US-FACT-01/02, RG-FACT-02 (CA-8) : le rendu porte numéro, émetteur, destinataire, lignes, TVA
 * ventilée, totaux, conditions de règlement, et la mention « acquittée » avec date/moyen/référence.
 */
final class FactureRenduApiTest extends FacturationApiTestCase
{
    public function testCa8RenduPortesLesMentionsLegales(): void
    {
        [$client, $entete] = $this->adminSurA();
        $vente = $this->creerVenteValidee($client, $entete);
        $facture = $client->request('POST', '/api/factures/depuis-vente', $entete + [
            'json' => ['vente' => '/api/ventes/' . $vente['id']],
        ])->toArray();

        $rendu = $client->request('GET', '/api/factures/' . $facture['id'] . '/rendu', $entete)->toArray();

        self::assertSame($facture['numero'], $rendu['numero']);
        self::assertNotEmpty($rendu['emetteur']);
        self::assertSame('Régie piscine A', $rendu['emetteur']['denomination']);
        self::assertNotNull($rendu['destinataire']);
        self::assertNotEmpty($rendu['lignes']);
        self::assertNotEmpty($rendu['ventilationTva']);
        self::assertArrayHasKey('totalHT', $rendu);
        self::assertArrayHasKey('totalTVA', $rendu);
        self::assertArrayHasKey('totalTTC', $rendu);
        self::assertNotNull($rendu['conditionsReglement']);
        self::assertTrue($rendu['mentionAcquittee']);
        self::assertNotNull($rendu['acquitteeLe']);
        self::assertNotNull($rendu['acquitteeMoyen']);
        self::assertSame($vente['numero'], $rendu['acquitteeReference']);
    }
}
