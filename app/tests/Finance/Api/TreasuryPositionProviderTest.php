<?php

declare(strict_types=1);

namespace App\Tests\Finance\Api;

use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Finance\Treasury\Entity\BankStatementLine;
use App\Finance\Treasury\Enum\BankStatementLineStatus;
use App\Tests\Finance\TreasuryApiTestCase;

/**
 * CA-4 (US-TRE-05, RG-TRE-05) : la position reflète la **somme exacte** des soldes d'ouverture et des
 * mouvements `reconciled` jusqu'à T — une ligne `suggested` n'est **pas** comptée.
 */
final class TreasuryPositionProviderTest extends TreasuryApiTestCase
{
    public function testSommeExacteSoldesOuvertureEtLignesRapprocheesUniquement(): void
    {
        [$client, $entete] = $this->adminSurA();

        $compte1 = $this->creerCompteBancaire($client, $entete, ['label' => 'Compte 1', 'openingBalance' => '1000.00']);
        $compte2 = $this->creerCompteBancaire($client, $entete, ['label' => 'Compte 2', 'openingBalance' => '500.00']);

        $this->ajouterLigne($client, $entete, $compte1['id'], '200.00', BankStatementLineStatus::Reconciled, '2026-08-01');
        // Ligne `suggested` : ne doit PAS être comptée dans la position (CA-4).
        $this->ajouterLigne($client, $entete, $compte1['id'], '999.00', BankStatementLineStatus::Suggested, '2026-08-01');
        $this->ajouterLigne($client, $entete, $compte2['id'], '-100.00', BankStatementLineStatus::Reconciled, '2026-08-02');
        // Ligne `reconciled` mais APRÈS la date `asOf` demandée : ne doit pas compter non plus.
        $this->ajouterLigne($client, $entete, $compte2['id'], '5000.00', BankStatementLineStatus::Reconciled, '2026-12-01');

        $position = $client->request('GET', '/api/finance/treasury/position', $entete + ['query' => ['asOf' => '2026-08-31']])->toArray(false);

        self::assertSame('1600.00', $position['balance']);

        $parCompte = [];
        foreach ($position['perAccount'] as $ligne) {
            $parCompte[$ligne['bankAccountId']] = $ligne['balance'];
        }
        self::assertSame('1200.00', $parCompte[$compte1['id']]);
        self::assertSame('400.00', $parCompte[$compte2['id']]);
    }

    /** @param array<string, mixed> $entete */
    private function ajouterLigne(Client $client, array $entete, string $idCompte, string $montant, BankStatementLineStatus $statut, string $date): void
    {
        $import = $this->creerImportManuel($client, $entete, $idCompte);
        $ligneJson = $this->creerLigneManuelle($client, $entete, $import['id'], $date, 'Mouvement', $montant);

        $ligne = $this->em()->getRepository(BankStatementLine::class)->find($ligneJson['id']);
        self::assertInstanceOf(BankStatementLine::class, $ligne);
        $ligne->setStatus($statut);
        $this->em()->flush();
    }
}
