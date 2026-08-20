<?php

declare(strict_types=1);

namespace App\Tests\Finance\Api;

use App\Compta\Entity\EcritureComptable;
use App\Finance\DataFixtures\FinanceFixtures;
use App\Finance\SupplierInvoice\Entity\SupplierInvoice;
use App\Tests\Finance\FinanceApiTestCase;

/**
 * CA-8 (US-SINV-08, RG-SINV-09) : l'avoir référence la facture d'origine, ne modifie **aucune** ligne
 * d'origine, génère une écriture miroir (`pieceExtourneDe`), Σdébit/crédit inversée. §4.8 spec : total
 * ou partiel.
 */
final class SupplierCreditNoteHandlerTest extends FinanceApiTestCase
{
    public function testAvoirTotalGenereEcritureMiroirSansModifierOrigine(): void
    {
        [$client, $entete] = $this->adminSurA();
        $facture = $this->factureValidee($client, $entete, 'FACT-AVOIR-001');
        $ecritureOrigineId = basename((string) $facture['ledgerEntry']);
        $ligneOrigineAvant = $this->entite(EcritureComptable::class, ['id' => $ecritureOrigineId]);
        $debitOrigineAvant = $ligneOrigineAvant->totalDebitCentimes();
        $creditOrigineAvant = $ligneOrigineAvant->totalCreditCentimes();

        $reponse = $client->request('POST', '/api/finance/supplier-invoices/' . $facture['id'] . '/credit-note', $entete + [
            'json' => ['reason' => 'Marchandise retournée intégralement'],
        ]);
        self::assertSame(201, $reponse->getStatusCode());
        $avoir = $reponse->toArray();

        self::assertSame('credit_note', $avoir['nature']);
        self::assertSame($facture['amountInclTax'], $avoir['amountInclTax']);

        // La facture d'origine n'est pas modifiée.
        $origineApres = $this->entite(EcritureComptable::class, ['id' => $ecritureOrigineId]);
        self::assertSame($debitOrigineAvant, $origineApres->totalDebitCentimes());
        self::assertSame($creditOrigineAvant, $origineApres->totalCreditCentimes());

        // Nouvelle écriture miroir, `pieceExtourneDe` pointant l'écriture d'origine, Σ inversée.
        $ecritureAvoirId = basename((string) $avoir['ledgerEntry']);
        self::assertNotSame($ecritureOrigineId, $ecritureAvoirId);
        $ecritureAvoir = $this->entite(EcritureComptable::class, ['id' => $ecritureAvoirId]);
        self::assertNotNull($ecritureAvoir->getPieceExtourneDe());
        self::assertSame($ecritureOrigineId, (string) $ecritureAvoir->getPieceExtourneDe()->getId());
        self::assertTrue($ecritureAvoir->estEquilibree());
        self::assertSame($creditOrigineAvant, $ecritureAvoir->totalDebitCentimes(), 'Débit de l\'avoir = crédit de l\'origine (miroir).');
        self::assertSame($debitOrigineAvant, $ecritureAvoir->totalCreditCentimes(), 'Crédit de l\'avoir = débit de l\'origine (miroir).');
    }

    public function testAvoirPartielMontantReduit(): void
    {
        [$client, $entete] = $this->adminSurA();
        $facture = $this->factureValidee($client, $entete, 'FACT-AVOIR-002');

        $reponse = $client->request('POST', '/api/finance/supplier-invoices/' . $facture['id'] . '/credit-note', $entete + [
            'json' => ['amount' => '100.00', 'reason' => 'Avoir partiel — un article manquant'],
        ]);
        self::assertSame(201, $reponse->getStatusCode());
        $avoir = $reponse->toArray();

        self::assertSame('100.00', $avoir['amountInclTax']);
        self::assertNotSame($facture['amountInclTax'], $avoir['amountInclTax']);

        $ecritureAvoir = $this->entite(EcritureComptable::class, ['id' => basename((string) $avoir['ledgerEntry'])]);
        self::assertTrue($ecritureAvoir->estEquilibree());
        self::assertSame(10000, $ecritureAvoir->totalDebitCentimes());
    }

    public function testFactureDraftOuAnnuleeNePeutPasRecevoirAvoir(): void
    {
        [$client, $entete] = $this->adminSurA();
        $idFournisseur = $this->idFournisseur(FinanceFixtures::FOURNISSEUR_ACTIF);
        $facture = $this->creerFactureAvecLigne($client, $entete, $idFournisseur, [], 'FACT-AVOIR-003');

        // Encore `draft` : jamais approuvée.
        $reponse = $client->request('POST', '/api/finance/supplier-invoices/' . $facture['id'] . '/credit-note', $entete + [
            'json' => ['reason' => 'Tentative sur brouillon'],
        ]);
        self::assertSame(409, $reponse->getStatusCode());

        $client->request('POST', '/api/finance/supplier-invoices/' . $facture['id'] . '/cancel', $entete);
        $reponseAnnulee = $client->request('POST', '/api/finance/supplier-invoices/' . $facture['id'] . '/credit-note', $entete + [
            'json' => ['reason' => 'Tentative sur annulée'],
        ]);
        self::assertSame(409, $reponseAnnulee->getStatusCode());
    }

    /** @return array<string, mixed> */
    private function factureValidee(\ApiPlatform\Symfony\Bundle\Test\Client $client, array $entete, string $numero): array
    {
        $idFournisseur = $this->idFournisseur(FinanceFixtures::FOURNISSEUR_ACTIF);
        $facture = $this->creerFactureAvecLigne($client, $entete, $idFournisseur, [], $numero);
        $client->request('POST', '/api/finance/supplier-invoices/' . $facture['id'] . '/approve', $entete);

        return $client->request('GET', '/api/supplier_invoices/' . $facture['id'], $entete)->toArray();
    }
}
