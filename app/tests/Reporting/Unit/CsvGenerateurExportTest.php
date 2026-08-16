<?php

declare(strict_types=1);

namespace App\Tests\Reporting\Unit;

use App\Reporting\Enum\FormatExport;
use App\Reporting\Exception\GenerationExportNonSupporteeException;
use App\Reporting\Service\Export\CsvGenerateurExport;
use App\Reporting\Service\Export\PdfGenerateurExportStub;
use App\Reporting\Service\Export\XlsxGenerateurExportStub;
use PHPUnit\Framework\TestCase;

/**
 * `CsvGenerateurExport` (§2.8 plan-reporting.md) : CSV réellement fonctionnel. Stubs PDF/XLSX :
 * exception explicite (Risque §9.9), jamais un plantage silencieux.
 */
final class CsvGenerateurExportTest extends TestCase
{
    public function testGenereUnCsvValideAvecEnTetesCorrectes(): void
    {
        $generateur = new CsvGenerateurExport();

        $genere = $generateur->generer(['indicateur', 'valeur'], [
            ['indicateur' => 'CA', 'valeur' => '120.00'],
            ['indicateur' => 'FREQUENTATION_CUMULEE', 'valeur' => '3'],
        ]);

        self::assertSame(FormatExport::Csv, $generateur->format());
        self::assertSame('csv', $genere->extension);
        self::assertStringContainsString('indicateur;valeur', $genere->contenu);
        self::assertStringContainsString('CA;120.00', $genere->contenu);
        self::assertStringContainsString('FREQUENTATION_CUMULEE;3', $genere->contenu);
    }

    public function testPdfStubLeveUneExceptionExplicite(): void
    {
        $stub = new PdfGenerateurExportStub();

        $this->expectException(GenerationExportNonSupporteeException::class);
        $this->expectExceptionMessageMatches('/PDF/');
        $stub->generer(['a'], []);
    }

    public function testXlsxStubLeveUneExceptionExplicite(): void
    {
        $stub = new XlsxGenerateurExportStub();

        $this->expectException(GenerationExportNonSupporteeException::class);
        $this->expectExceptionMessageMatches('/XLSX/');
        $stub->generer(['a'], []);
    }
}
