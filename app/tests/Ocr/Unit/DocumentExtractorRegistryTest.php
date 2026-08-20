<?php

declare(strict_types=1);

namespace App\Tests\Ocr\Unit;

use App\Ocr\Adapter\ManualExtractorAdapter;
use App\Ocr\Service\DocumentExtractorRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Résolution d'un adaptateur `DocumentExtractor` par `provider()` (itérateur taggé `ocr.extractor`) —
 * même patron que `App\Compta\Export\ExportComptableResolver`.
 */
final class DocumentExtractorRegistryTest extends TestCase
{
    public function testResoutLadaptateurParSonProvider(): void
    {
        $manuel = new ManualExtractorAdapter();
        $registre = new DocumentExtractorRegistry([$manuel]);

        self::assertSame($manuel, $registre->pour('manual'));
    }

    public function testErreurExpliciteSiProviderInconnu(): void
    {
        $registre = new DocumentExtractorRegistry([new ManualExtractorAdapter()]);

        $this->expectException(\InvalidArgumentException::class);
        $registre->pour('provider-inexistant');
    }
}
