<?php

declare(strict_types=1);

namespace App\Tests\Finance\Api;

use App\Finance\DataFixtures\FinanceFixtures;
use App\Tests\Finance\FinanceApiTestCase;

/**
 * CA-6 (US-SINV-06, RG-SINV-07) : règlement total -> `paid` + lettrage groupé (même
 * `reconciliationCode` sur les deux lignes 401) ; règlement partiel -> `partially_paid`, solde recalculé,
 * **aucun** lettrage tenté (§0.8 point 4 du plan). RG-SINV-08 : gel en cas de litige.
 */
final class SupplierPaymentHandlerTest extends FinanceApiTestCase
{
    public function testReglementTotalPasseAPaidEtLettre(): void
    {
        [$client, $entete] = $this->adminSurA();
        $facture = $this->factureValidee($client, $entete, 'FACT-PAY-001');
        self::assertSame('480.00', $facture['amountInclTax']);

        $reponse = $client->request('POST', '/api/supplier_payments', $entete + [
            'json' => [
                'supplierInvoice' => '/api/supplier_invoices/' . $facture['id'],
                'date' => '2026-09-15',
                'amount' => '480.00',
                'paymentMethod' => '/api/moyen_paiements/' . $this->idMoyenPaiement('virement'),
            ],
        ]);
        self::assertSame(201, $reponse->getStatusCode());
        $reglement = $reponse->toArray();
        self::assertNotNull($reglement['reconciliationCode']);

        $rechargee = $client->request('GET', '/api/supplier_invoices/' . $facture['id'], $entete)->toArray();
        self::assertSame('paid', $rechargee['status']);
    }

    public function testReglementPartielPasseAPartiallyPaidSansLettrage(): void
    {
        [$client, $entete] = $this->adminSurA();
        $facture = $this->factureMilleEuros($client, $entete, 'FACT-PAY-002');

        $reponse = $client->request('POST', '/api/supplier_payments', $entete + [
            'json' => [
                'supplierInvoice' => '/api/supplier_invoices/' . $facture['id'],
                'date' => '2026-09-15',
                'amount' => '400.00',
                'paymentMethod' => '/api/moyen_paiements/' . $this->idMoyenPaiement('virement'),
            ],
        ]);
        self::assertSame(201, $reponse->getStatusCode());
        $reglement = $reponse->toArray();
        self::assertNull($reglement['reconciliationCode'], 'Aucun lettrage tant que le solde n\'est pas nul (§0.8 point 4).');

        $rechargee = $client->request('GET', '/api/supplier_invoices/' . $facture['id'], $entete)->toArray();
        self::assertSame('partially_paid', $rechargee['status']);
    }

    public function testReglementSuperieurAuSoldeRejete(): void
    {
        [$client, $entete] = $this->adminSurA();
        $facture = $this->factureValidee($client, $entete, 'FACT-PAY-003');

        $reponse = $client->request('POST', '/api/supplier_payments', $entete + [
            'json' => [
                'supplierInvoice' => '/api/supplier_invoices/' . $facture['id'],
                'date' => '2026-09-15',
                'amount' => '999999.00',
                'paymentMethod' => '/api/moyen_paiements/' . $this->idMoyenPaiement('virement'),
            ],
        ]);

        self::assertSame(422, $reponse->getStatusCode());
    }

    public function testReglementSurFactureLitigieuseRefuse(): void
    {
        [$client, $entete] = $this->adminSurA();
        $facture = $this->factureValidee($client, $entete, 'FACT-PAY-004');

        $client->request('POST', '/api/finance/supplier-invoices/' . $facture['id'] . '/dispute', $entete + ['json' => ['reason' => 'Marchandise non conforme']]);

        $reponse = $client->request('POST', '/api/supplier_payments', $entete + [
            'json' => [
                'supplierInvoice' => '/api/supplier_invoices/' . $facture['id'],
                'date' => '2026-09-15',
                'amount' => '480.00',
                'paymentMethod' => '/api/moyen_paiements/' . $this->idMoyenPaiement('virement'),
            ],
        ]);

        self::assertSame(409, $reponse->getStatusCode());
    }

    /** @return array<string, mixed> */
    private function factureValidee(\ApiPlatform\Symfony\Bundle\Test\Client $client, array $entete, string $numero): array
    {
        $idFournisseur = $this->idFournisseur(FinanceFixtures::FOURNISSEUR_ACTIF);
        $facture = $this->creerFactureAvecLigne($client, $entete, $idFournisseur, [], $numero);
        $client->request('POST', '/api/finance/supplier-invoices/' . $facture['id'] . '/approve', $entete);

        return $client->request('GET', '/api/supplier_invoices/' . $facture['id'], $entete)->toArray();
    }

    /** Facture de 1000 € TTC (RG-M6-05 : quantité 200 x 4,1666 €HT au taux 20 %, arrondi à 1000,00 €). */
    private function factureMilleEuros(\ApiPlatform\Symfony\Bundle\Test\Client $client, array $entete, string $numero): array
    {
        $idFournisseur = $this->idFournisseur(FinanceFixtures::FOURNISSEUR_ACTIF);
        $facture = $this->creerFactureAvecLigne($client, $entete, $idFournisseur, [
            'quantity' => '1.000',
            'unitPriceExclTax' => '833.3333',
        ], $numero);
        $rechargee = $client->request('GET', '/api/supplier_invoices/' . $facture['id'], $entete)->toArray();
        self::assertSame('1000.00', $rechargee['amountInclTax']);

        $client->request('POST', '/api/finance/supplier-invoices/' . $facture['id'] . '/approve', $entete);

        return $client->request('GET', '/api/supplier_invoices/' . $facture['id'], $entete)->toArray();
    }
}
