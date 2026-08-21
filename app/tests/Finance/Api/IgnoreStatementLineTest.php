<?php

declare(strict_types=1);

namespace App\Tests\Finance\Api;

use App\Tests\Finance\TreasuryApiTestCase;

/** §7 cas limite spec — marquage `ignored` d'une ligne sans correspondance possible, motif requis. */
final class IgnoreStatementLineTest extends TreasuryApiTestCase
{
    public function testIgnoreSansMotifRefuse422(): void
    {
        [$client, $entete] = $this->adminSurA();
        $compte = $this->creerCompteBancaire($client, $entete);
        $import = $this->creerImportManuel($client, $entete, $compte['id']);
        $ligne = $this->creerLigneManuelle($client, $entete, $import['id'], '2026-08-15', 'Frais divers', '-3.50');

        $reponse = $client->request('POST', '/api/finance/treasury/statement-lines/' . $ligne['id'] . '/ignore', $entete + ['json' => ['reason' => '']]);

        self::assertSame(422, $reponse->getStatusCode());
    }

    public function testIgnoreAvecMotifPasseLeStatutAIgnored(): void
    {
        [$client, $entete] = $this->adminSurA();
        $compte = $this->creerCompteBancaire($client, $entete);
        $import = $this->creerImportManuel($client, $entete, $compte['id']);
        $ligne = $this->creerLigneManuelle($client, $entete, $import['id'], '2026-08-15', 'Frais divers', '-3.50');

        $reponse = $client->request('POST', '/api/finance/treasury/statement-lines/' . $ligne['id'] . '/ignore', $entete + ['json' => ['reason' => 'Frais bancaires non identifiés']]);

        self::assertSame(201, $reponse->getStatusCode());
        $resultat = $reponse->toArray();
        self::assertSame('ignored', $resultat['status']);
        self::assertSame('Frais bancaires non identifiés', $resultat['ignoredReason']);
    }
}
