<?php

declare(strict_types=1);

namespace App\Tests\Finance\Api;

use App\Tests\Finance\TreasuryApiTestCase;

/**
 * US-TRE-09 (§0.8 du plan) — le tableau de bord des écarts est une requête **live**, visible dès le
 * délai dépassé, indépendamment du passage (ou non) de `finance:treasury:detecter-ecarts`.
 */
final class DiscrepancyDashboardProviderTest extends TreasuryApiTestCase
{
    public function testTableauDeBordListeIndependammentDuFlagDeNotification(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->request('POST', '/api/treasury_settings', $entete + [
            'json' => ['establishment' => '/api/etablissements/' . $this->idEtablissement('Piscine A'), 'unmatchedAlertDelayDays' => 0],
        ]);

        $compte = $this->creerCompteBancaire($client, $entete);
        $import = $this->creerImportManuel($client, $entete, $compte['id']);
        $ligne = $this->creerLigneManuelle($client, $entete, $import['id'], '2026-08-01', 'Virement non identifié', '75.00');

        // Aucun passage de la commande planifiée — le tableau de bord doit tout de même lister la ligne.
        $tableau = $client->request('GET', '/api/finance/treasury/discrepancies', $entete)->toArray(false);

        $ids = array_column($tableau, 'id');
        self::assertContains($ligne['id'], $ids);
    }

    public function testLigneRecenteSousLeDelaiNApparaitPas(): void
    {
        [$client, $entete] = $this->adminSurA();
        // Délai par défaut (15 j) : une ligne qui vient d'être créée n'apparaît pas.
        $compte = $this->creerCompteBancaire($client, $entete);
        $import = $this->creerImportManuel($client, $entete, $compte['id']);
        $ligne = $this->creerLigneManuelle($client, $entete, $import['id'], date('Y-m-d'), 'Virement récent', '75.00');

        $tableau = $client->request('GET', '/api/finance/treasury/discrepancies', $entete)->toArray(false);

        $ids = array_column($tableau, 'id');
        self::assertNotContains($ligne['id'], $ids);
    }
}
