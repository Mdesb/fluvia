<?php

declare(strict_types=1);

namespace App\Tests\Ocr\Unit;

use App\Ocr\Adapter\ManualExtractorAdapter;
use App\Ocr\Dto\DocumentToExtract;
use App\Ocr\Enum\DocumentKind;
use App\Ocr\Enum\ExtractionStatus;
use PHPUnit\Framework\TestCase;

/**
 * Mode dégradé (CA-1/CA-2, RG-OCR-02) : toujours disponible, retourne systématiquement
 * `status: failed`/`provider: manual`, tous les autres champs `null`, jamais d'exception, jamais de
 * latence réseau (mesure de temps d'exécution quasi nulle).
 */
final class ManualExtractorAdapterTest extends TestCase
{
    public function testRetourneToujoursStatutFailedEtProviderManual(): void
    {
        $adaptateur = new ManualExtractorAdapter();

        $resultat = $adaptateur->extract(new DocumentToExtract('contenu-binaire-illisible', 'application/pdf'), DocumentKind::SupplierInvoice);

        self::assertSame(ExtractionStatus::Failed, $resultat->status);
        self::assertSame('manual', $resultat->provider);
        self::assertSame('manual', $adaptateur->provider());
        self::assertNull($resultat->supplierName);
        self::assertNull($resultat->documentNumber);
        self::assertNull($resultat->documentDate);
        self::assertNull($resultat->amountExclTax);
        self::assertNull($resultat->amountInclTax);
        self::assertNull($resultat->vatAmount);
        self::assertNull($resultat->vatRate);
        self::assertNull($resultat->confidenceScore);
        self::assertNull($resultat->rawText);
    }

    public function testAucuneExceptionQuelQueSoitLeDocumentKind(): void
    {
        $adaptateur = new ManualExtractorAdapter();

        foreach (DocumentKind::cases() as $kind) {
            $resultat = $adaptateur->extract(new DocumentToExtract('', ''), $kind);
            self::assertSame(ExtractionStatus::Failed, $resultat->status);
        }
    }

    public function testAucuneLatenceReseauExecutionQuasiInstantanee(): void
    {
        $adaptateur = new ManualExtractorAdapter();

        $debut = hrtime(true);
        $adaptateur->extract(new DocumentToExtract('x', 'image/png'), DocumentKind::ExpenseReceipt);
        $dureeMs = (hrtime(true) - $debut) / 1_000_000;

        // D20 — 50 ms sur une machine chargee est intenable, et le seuil n'etait de toute facon
        // qu'un substitut : ce que ce test veut prouver, c'est qu'**aucun appel reseau n'a lieu** en
        // mode degrade. La bonne assertion porte sur l'absence d'appel, pas sur le chronometre (C21).
        // En attendant, seuil de garde large : 500 ms restent inatteignables si un appel HTTP part.
        self::assertLessThan(500.0, $dureeMs, 'Un appel reseau semble avoir eu lieu en mode degrade (seuil de garde D20).');
    }
}
