<?php

declare(strict_types=1);

namespace App\Tests\Finance\Api;

use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Tests\Finance\ExpenseReportApiTestCase;

/**
 * RG-EXP-03 — l'OCR pré-remplit sans jamais valider automatiquement ; mode dégradé (aucun
 * `OcrProviderConfig` seedé pour cet établissement) : la saisie manuelle reste pleinement
 * fonctionnelle.
 */
final class ExpenseReportOcrTest extends ExpenseReportApiTestCase
{
    public function testExtractionPreRemplitSansValiderAutomatiquement(): void
    {
        [$client, $entete] = $this->salarie('expense.ocr@itcotation.com');

        $avant = \count($this->notesDeA($client, $entete));

        $extraction = $client->request('POST', '/api/finance/expense-reports/extract', $entete + [
            'json' => [
                'content' => base64_encode('%PDF-1.4 contenu de test'),
                'mimeType' => 'application/pdf',
            ],
        ])->toArray();

        self::assertArrayHasKey('status', $extraction);
        self::assertSame('failed', $extraction['status'], 'Aucun OcrProviderConfig seedé (RG-OCR-06) : mode dégradé.');

        // RG-EXP-03 : aucune création automatique de ligne ni de note.
        self::assertCount($avant, $this->notesDeA($client, $entete));
    }

    public function testOcrNonConfigureModeDegradeSaisieManuelleFonctionne(): void
    {
        [$client, $entete, $idEmploye] = $this->salarie('expense.ocr-degrade@itcotation.com');

        $note = $this->creerNoteAvecLigne($client, $entete, $idEmploye);

        self::assertSame('draft', $note['status']);
        self::assertCount(1, $note['lines']);
    }

    /** @param array<string, mixed> $entete
     * @return list<array<string, mixed>> */
    private function notesDeA(Client $client, array $entete): array
    {
        return $client->request('GET', '/api/expense_reports', $entete)->toArray()['member'] ?? [];
    }
}
