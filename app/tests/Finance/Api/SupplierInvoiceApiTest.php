<?php

declare(strict_types=1);

namespace App\Tests\Finance\Api;

use App\Finance\DataFixtures\FinanceFixtures;
use App\Tests\Finance\FinanceApiTestCase;

/**
 * CA-1 (US-SINV-01, RG-SINV-01) : réutilisation stricte de `App\Stock\Entity\Fournisseur`, aucune
 * fiche fournisseur dupliquée ; un fournisseur inactif ne peut plus recevoir de **nouvelle** facture.
 */
final class SupplierInvoiceApiTest extends FinanceApiTestCase
{
    public function testCreationBrouillonSansCommandeNiReception(): void
    {
        [$client, $entete] = $this->adminSurA();
        $idFournisseur = $this->idFournisseur(FinanceFixtures::FOURNISSEUR_ACTIF);

        $facture = $this->creerFactureAvecLigne($client, $entete, $idFournisseur);

        self::assertSame('draft', $facture['status']);
        self::assertSame('manual', $facture['source']);
        self::assertNull($facture['purchaseOrder'] ?? null);
        self::assertNull($facture['goodsReceipt'] ?? null);
        self::assertCount(1, $facture['lines']);
        self::assertSame('400.00', $facture['amountExclTax']);
        self::assertSame('480.00', $facture['amountInclTax']);
    }

    public function testFournisseurInactifRefuseNouvelleFacture(): void
    {
        [$client, $entete] = $this->adminSurA();
        $idFournisseur = $this->idFournisseur(FinanceFixtures::FOURNISSEUR_INACTIF);

        $client->request('POST', '/api/supplier_invoices', $entete + [
            'json' => [
                'establishment' => '/api/etablissements/' . $this->idEtablissement('Piscine A'),
                'businessProfile' => '/api/profil_exploitants/' . $this->idProfilExploitant(),
                'supplier' => '/api/stock_fournisseurs/' . $idFournisseur,
                'supplierInvoiceNumber' => 'FACT-INACTIF-001',
                'invoiceDate' => '2026-08-01',
                'dueDate' => '2026-09-01',
            ],
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testLigneRecalculeLesTotauxDeLaFacture(): void
    {
        [$client, $entete] = $this->adminSurA();
        $idFournisseur = $this->idFournisseur(FinanceFixtures::FOURNISSEUR_ACTIF);

        $facture = $client->request('POST', '/api/supplier_invoices', $entete + [
            'json' => [
                'establishment' => '/api/etablissements/' . $this->idEtablissement('Piscine A'),
                'businessProfile' => '/api/profil_exploitants/' . $this->idProfilExploitant(),
                'supplier' => '/api/stock_fournisseurs/' . $idFournisseur,
                'supplierInvoiceNumber' => 'FACT-2026-0002',
                'invoiceDate' => '2026-08-01',
                'dueDate' => '2026-09-01',
            ],
        ])->toArray();

        self::assertSame('0.00', $facture['amountExclTax']);

        $client->request('POST', '/api/supplier_invoice_lines', $entete + [
            'json' => [
                'supplierInvoice' => '/api/supplier_invoices/' . $facture['id'],
                'description' => 'Ligne 1',
                'quantity' => '10.000',
                'unitPriceExclTax' => '10.00',
                'vatRate' => '/api/taux_tvas/' . $this->idTauxTva('20.00'),
                'expenseNatureCode' => FinanceFixtures::EXPENSE_NATURE_MAPPEE,
            ],
        ]);
        $client->request('POST', '/api/supplier_invoice_lines', $entete + [
            'json' => [
                'supplierInvoice' => '/api/supplier_invoices/' . $facture['id'],
                'description' => 'Ligne 2',
                'quantity' => '5.000',
                'unitPriceExclTax' => '2.00',
                'vatRate' => '/api/taux_tvas/' . $this->idTauxTva('20.00'),
                'expenseNatureCode' => FinanceFixtures::EXPENSE_NATURE_MAPPEE,
            ],
        ]);

        $recharge = $client->request('GET', '/api/supplier_invoices/' . $facture['id'], $entete)->toArray();

        self::assertSame('110.00', $recharge['amountExclTax']);
        self::assertSame('132.00', $recharge['amountInclTax']);
        self::assertCount(2, $recharge['lines']);
    }
}
