<?php

declare(strict_types=1);

namespace App\Tests\Finance\Api;

use App\Finance\DataFixtures\FinanceFixtures;
use App\Tests\Finance\FinanceApiTestCase;

/** CA-7 (US-SINV-07, RG-SINV-08) : motif obligatoire, gèle les règlements ; §4.7 spec : clôture tracée. */
final class SupplierInvoiceDisputeTest extends FinanceApiTestCase
{
    public function testOuvertureSansMotifRefusee(): void
    {
        $facture = $this->factureValidee('FACT-DISP-001');
        [$client, $entete] = $this->adminSurA();

        $reponse = $client->request('POST', '/api/finance/supplier-invoices/' . $facture['id'] . '/dispute', $entete + ['json' => []]);

        self::assertSame(422, $reponse->getStatusCode());
    }

    public function testOuvertureAvecMotifGeleLesReglements(): void
    {
        $facture = $this->factureValidee('FACT-DISP-002');
        [$client, $entete] = $this->adminSurA();

        $reponse = $client->request('POST', '/api/finance/supplier-invoices/' . $facture['id'] . '/dispute', $entete + [
            'json' => ['reason' => 'Livraison incomplète'],
        ]);
        self::assertSame(201, $reponse->getStatusCode());
        $disputee = $reponse->toArray();
        self::assertSame('disputed', $disputee['status']);
        self::assertSame('Livraison incomplète', $disputee['disputeReason']);

        $reglement = $client->request('POST', '/api/supplier_payments', $entete + [
            'json' => [
                'supplierInvoice' => '/api/supplier_invoices/' . $facture['id'],
                'date' => '2026-09-15',
                'amount' => '480.00',
                'paymentMethod' => '/api/moyen_paiements/' . $this->idMoyenPaiement('virement'),
            ],
        ]);
        self::assertSame(409, $reglement->getStatusCode());
    }

    public function testClotureLitigeTraceeMotifResolution(): void
    {
        $facture = $this->factureValidee('FACT-DISP-003');
        [$client, $entete] = $this->adminSurA();

        $client->request('POST', '/api/finance/supplier-invoices/' . $facture['id'] . '/dispute', $entete + ['json' => ['reason' => 'Litige initial']]);

        $reponse = $client->request('POST', '/api/finance/supplier-invoices/' . $facture['id'] . '/resolve-dispute', $entete + [
            'json' => ['resolutionReason' => 'Avoir reçu du fournisseur, litige clos'],
        ]);
        self::assertSame(201, $reponse->getStatusCode());
        $resolue = $reponse->toArray();

        self::assertSame('to_pay', $resolue['status'], 'Aucun règlement enregistré avant le litige : retour à `to_pay`.');
        self::assertSame('Avoir reçu du fournisseur, litige clos', $resolue['disputeResolutionReason']);
        self::assertSame('Litige initial', $resolue['disputeReason'], 'Le motif d\'ouverture reste tracé après clôture.');
    }

    /** @return array<string, mixed> */
    private function factureValidee(string $numero): array
    {
        [$client, $entete] = $this->adminSurA();
        $idFournisseur = $this->idFournisseur(FinanceFixtures::FOURNISSEUR_ACTIF);
        $facture = $this->creerFactureAvecLigne($client, $entete, $idFournisseur, [], $numero);
        $client->request('POST', '/api/finance/supplier-invoices/' . $facture['id'] . '/approve', $entete);

        return $client->request('GET', '/api/supplier_invoices/' . $facture['id'], $entete)->toArray();
    }
}
