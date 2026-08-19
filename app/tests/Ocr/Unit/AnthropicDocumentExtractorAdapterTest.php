<?php

declare(strict_types=1);

namespace App\Tests\Ocr\Unit;

use App\Ocr\Adapter\AnthropicDocumentExtractorAdapter;
use App\Ocr\Dto\DocumentToExtract;
use App\Ocr\Enum\DocumentKind;
use App\Ocr\Enum\ExtractionStatus;
use App\Ocr\Exception\OcrProviderUnavailableException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Implémentation réelle Anthropic (US-OCR-03, RG-OCR-03), `HttpClient` mocké :
 * CA-3 — réponse JSON conforme → `status: success` + champs + `confidenceScore` ;
 * CA-3bis — JSON non conforme → `status: low_confidence` (jamais d'exception) ;
 * échec transport (5xx/timeout) → lève `OcrProviderUnavailableException`.
 */
final class AnthropicDocumentExtractorAdapterTest extends TestCase
{
    public function testReponseJsonConformeRetourneSuccesAvecChampsEtConfiance(): void
    {
        $corpsAnthropic = [
            'content' => [
                [
                    'type' => 'text',
                    'text' => json_encode([
                        'supplierName' => 'ACME Fournitures',
                        'documentNumber' => 'FA-2026-042',
                        'documentDate' => '2026-01-15',
                        'amountExclTax' => '100.00',
                        'amountInclTax' => '120.00',
                        'vatAmount' => '20.00',
                        'vatRate' => '20.00',
                        'confidenceScore' => 0.93,
                        'rawText' => 'Facture ACME...',
                    ], JSON_THROW_ON_ERROR),
                ],
            ],
        ];
        $httpClient = new MockHttpClient(new MockResponse(json_encode($corpsAnthropic, JSON_THROW_ON_ERROR), ['http_code' => 200]));
        $adaptateur = new AnthropicDocumentExtractorAdapter($httpClient, 'sk-ant-test-key');

        $resultat = $adaptateur->extract(new DocumentToExtract('contenu-facture', 'image/png'), DocumentKind::SupplierInvoice);

        self::assertSame(ExtractionStatus::Success, $resultat->status);
        self::assertSame('anthropic', $resultat->provider);
        self::assertSame('ACME Fournitures', $resultat->supplierName);
        self::assertSame('FA-2026-042', $resultat->documentNumber);
        self::assertSame('2026-01-15', $resultat->documentDate?->format('Y-m-d'));
        self::assertSame('100.00', $resultat->amountExclTax);
        self::assertSame('120.00', $resultat->amountInclTax);
        self::assertSame(0.93, $resultat->confidenceScore);
    }

    public function testReponseJsonNonConformeRetourneLowConfidenceJamaisException(): void
    {
        $corpsAnthropic = [
            'content' => [
                ['type' => 'text', 'text' => 'ceci n\'est pas du JSON valide {{{'],
            ],
        ];
        $httpClient = new MockHttpClient(new MockResponse(json_encode($corpsAnthropic, JSON_THROW_ON_ERROR), ['http_code' => 200]));
        $adaptateur = new AnthropicDocumentExtractorAdapter($httpClient, 'sk-ant-test-key');

        $resultat = $adaptateur->extract(new DocumentToExtract('x', 'image/png'), DocumentKind::ExpenseReceipt);

        self::assertSame(ExtractionStatus::LowConfidence, $resultat->status);
        self::assertSame('anthropic', $resultat->provider);
    }

    public function testEchecTransport5xxLeveOcrProviderUnavailableException(): void
    {
        $httpClient = new MockHttpClient(new MockResponse('', ['http_code' => 500]));
        $adaptateur = new AnthropicDocumentExtractorAdapter($httpClient, 'sk-ant-test-key');

        $this->expectException(OcrProviderUnavailableException::class);
        $adaptateur->extract(new DocumentToExtract('x', 'image/png'), DocumentKind::SupplierInvoice);
    }

    public function testEchecTransportTimeoutLeveOcrProviderUnavailableException(): void
    {
        $httpClient = new MockHttpClient(new MockResponse('', ['error' => 'Connection timed out']));
        $adaptateur = new AnthropicDocumentExtractorAdapter($httpClient, 'sk-ant-test-key');

        $this->expectException(OcrProviderUnavailableException::class);
        $adaptateur->extract(new DocumentToExtract('x', 'image/png'), DocumentKind::SupplierInvoice);
    }

    public function testCleApiVideDegradeSansAppelReseau(): void
    {
        $httpClient = new MockHttpClient(function (): never {
            self::fail('Aucun appel réseau attendu quand la clé API est vide.');
        });
        $adaptateur = new AnthropicDocumentExtractorAdapter($httpClient, '');

        $resultat = $adaptateur->extract(new DocumentToExtract('x', 'image/png'), DocumentKind::SupplierInvoice);

        self::assertSame(ExtractionStatus::Failed, $resultat->status);
        self::assertSame('anthropic', $resultat->provider);
    }
}
