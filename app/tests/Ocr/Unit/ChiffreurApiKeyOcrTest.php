<?php

declare(strict_types=1);

namespace App\Tests\Ocr\Unit;

use App\Ocr\Service\ChiffreurApiKeyOcr;
use PHPUnit\Framework\TestCase;

/**
 * Coffre clé API OCR (`ChiffreurApiKeyOcr`, réutilise `App\Securite\Crypto\ChiffreurSecret` par
 * composition, RG-OCR-06) : round-trip chiffrer/déchiffrer, échec propre sur valeur corrompue — même
 * patron que `App\Tests\Sepa\Unit\ChiffreurIbanTest`.
 */
final class ChiffreurApiKeyOcrTest extends TestCase
{
    private const CLE_API_DEMO = 'sk-ant-demo-1234567890abcdef';

    public function testRoundTripChiffrerPuisDechiffrerRestitueLaCleEnClair(): void
    {
        $chiffreur = new ChiffreurApiKeyOcr('cle-test-chiffrement-ocr');

        $chiffre = $chiffreur->chiffrer(self::CLE_API_DEMO);
        $clair = $chiffreur->dechiffrer($chiffre);

        self::assertSame(self::CLE_API_DEMO, $clair);
    }

    public function testLeTexteChiffreNeContientJamaisLaCleEnClair(): void
    {
        $chiffreur = new ChiffreurApiKeyOcr('cle-test-chiffrement-ocr');

        $chiffre = $chiffreur->chiffrer(self::CLE_API_DEMO);

        self::assertStringNotContainsString(self::CLE_API_DEMO, $chiffre);
    }

    public function testChiffrerEstNonDeterministeNonceAleatoire(): void
    {
        $chiffreur = new ChiffreurApiKeyOcr('cle-test-chiffrement-ocr');

        $chiffreA = $chiffreur->chiffrer(self::CLE_API_DEMO);
        $chiffreB = $chiffreur->chiffrer(self::CLE_API_DEMO);

        self::assertNotSame($chiffreA, $chiffreB);
        self::assertSame($chiffreur->dechiffrer($chiffreA), $chiffreur->dechiffrer($chiffreB));
    }

    public function testDechiffrerAvecUneAutreCleEchoue(): void
    {
        $chiffreurA = new ChiffreurApiKeyOcr('cle-a');
        $chiffreurB = new ChiffreurApiKeyOcr('cle-b');

        $chiffre = $chiffreurA->chiffrer(self::CLE_API_DEMO);

        $this->expectException(\RuntimeException::class);
        $chiffreurB->dechiffrer($chiffre);
    }

    public function testDechiffrerUneValeurInvalideEchouePropremement(): void
    {
        $chiffreur = new ChiffreurApiKeyOcr('cle-test-chiffrement-ocr');

        $this->expectException(\RuntimeException::class);
        $chiffreur->dechiffrer('valeur-non-chiffree-invalide');
    }
}
