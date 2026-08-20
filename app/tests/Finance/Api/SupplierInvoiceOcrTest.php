<?php

declare(strict_types=1);

namespace App\Tests\Finance\Api;

use App\Finance\DataFixtures\FinanceFixtures;
use App\Tests\Finance\FinanceApiTestCase;

/**
 * CA-2 (US-SINV-02, RG-SINV-02) — l'OCR pré-remplit sans jamais valider automatiquement ; mode dégradé
 * (aucun `OcrProviderConfig`) : la saisie manuelle reste pleinement fonctionnelle.
 */
final class SupplierInvoiceOcrTest extends FinanceApiTestCase
{
    public function testExtractionPreRemplitSansValiderAutomatiquement(): void
    {
        [$client, $entete] = $this->adminSurA();

        $avant = \count($this->facturesDeA($client, $entete));

        $extraction = $client->request('POST', '/api/finance/supplier-invoices/extract', $entete + [
            'json' => [
                'content' => base64_encode('%PDF-1.4 contenu de test'),
                'mimeType' => 'application/pdf',
            ],
        ])->toArray();

        self::assertArrayHasKey('status', $extraction);
        // Aucun `OcrProviderConfig` configuré pour l'établissement (RG-OCR-06) : mode dégradé.
        self::assertSame('failed', $extraction['status']);

        // CA-2 : aucune création automatique — la collection de factures n'a pas bougé.
        self::assertCount($avant, $this->facturesDeA($client, $entete));
    }

    public function testOcrNonConfigureModeDegradeSaisieManuelleFonctionne(): void
    {
        [$client, $entete] = $this->adminSurA();
        $idFournisseur = $this->idFournisseur(FinanceFixtures::FOURNISSEUR_ACTIF);

        // Aucun `OcrProviderConfig` n'est seedé par `FinanceFixtures`/`OcrFixtures` pour cet
        // établissement : `DocumentExtractor` renvoie `failed` (déjà vérifié ci-dessus), la création
        // manuelle standard doit malgré tout aboutir normalement (mode dégradé, invariant noyau
        // commun #5).
        $facture = $this->creerFactureAvecLigne($client, $entete, $idFournisseur, [], 'FACT-OCR-DEGRADE');

        self::assertSame('draft', $facture['status']);
        self::assertSame('manual', $facture['source']);
    }

    /** @return list<array<string, mixed>> */
    private function facturesDeA(\ApiPlatform\Symfony\Bundle\Test\Client $client, array $entete): array
    {
        return $client->request('GET', '/api/supplier_invoices', $entete)->toArray()['member'] ?? [];
    }
}
