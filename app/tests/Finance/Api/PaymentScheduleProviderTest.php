<?php

declare(strict_types=1);

namespace App\Tests\Finance\Api;

use App\Finance\DataFixtures\FinanceFixtures;
use App\Finance\SupplierInvoice\Entity\SupplierInvoice;
use App\Finance\SupplierInvoice\Service\SupplierInvoiceBalanceCalculator;
use App\Finance\Treasury\Service\PaymentScheduleCalculator;
use App\Tests\Finance\TreasuryApiTestCase;

/**
 * CA-5 (US-TRE-06/07, RG-TRE-07) + §0.8 du plan — dégradation propre : une base sans `Facture`/
 * `RemiseSepa`/`SupplierInvoice` correspondante renvoie simplement des sections vides, jamais une
 * exception.
 */
final class PaymentScheduleProviderTest extends TreasuryApiTestCase
{
    public function testTenantSansSepaEcheancierSansSectionSepaSansErreur(): void
    {
        [$client, $entete] = $this->adminSurA();

        $reponse = $client->request('GET', '/api/finance/treasury/payment-schedule', $entete);

        self::assertSame(200, $reponse->getStatusCode());
        $corps = $reponse->toArray(false);
        self::assertSame([], $corps['entries']);
        self::assertSame([], $corps['exits']);
    }

    /** §0.8 du plan — pas de second calcul de solde réinventé : réutilise `SupplierInvoiceBalanceCalculator::soldeCentimes()` tel quel. */
    public function testSoldeFournisseurReutiliseSupplierInvoiceBalanceCalculator(): void
    {
        [$client, $entete] = $this->adminSurA();
        $idFournisseur = $this->idFournisseur(FinanceFixtures::FOURNISSEUR_ACTIF);
        $facture = $this->creerFactureAvecLigne($client, $entete, $idFournisseur, [], 'FACT-TRE-SCHED-001');
        $client->request('POST', '/api/finance/supplier-invoices/' . $facture['id'] . '/approve', $entete);

        $calculator = static::getContainer()->get(PaymentScheduleCalculator::class);
        \assert($calculator instanceof PaymentScheduleCalculator);
        $solde = static::getContainer()->get(SupplierInvoiceBalanceCalculator::class);
        \assert($solde instanceof SupplierInvoiceBalanceCalculator);

        $entiteFacture = $this->em()->getRepository(SupplierInvoice::class)->find($facture['id']);
        self::assertInstanceOf(SupplierInvoice::class, $entiteFacture);

        $resultat = $calculator->echeancier([$entiteFacture->getEstablishment()->getId()->toBinary()], new \DateTimeImmutable('2026-08-01'), new \DateTimeImmutable('2026-12-31'));

        $exitFacture = null;
        foreach ($resultat['exits'] as $exit) {
            if ($exit['sourceId'] === $facture['id']) {
                $exitFacture = $exit;
            }
        }
        self::assertNotNull($exitFacture, 'La facture fournisseur validée doit apparaître dans exits[].');
        self::assertSame($solde->soldeCentimes($entiteFacture), $exitFacture['amountCents']);
        self::assertSame('supplier_invoice', $exitFacture['source']);
    }
}
