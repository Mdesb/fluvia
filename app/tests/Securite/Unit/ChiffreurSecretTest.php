<?php

declare(strict_types=1);

namespace App\Tests\Securite\Unit;

use App\Securite\Crypto\ChiffreurSecret;
use PHPUnit\Framework\TestCase;

/**
 * §2.3 plan-backoffice.md — chiffrement réversible (libsodium) du secret MFA au repos.
 */
final class ChiffreurSecretTest extends TestCase
{
    public function testChiffrerPuisDechiffrerRestitueLaValeurOriginale(): void
    {
        $chiffreur = new ChiffreurSecret(base64_encode(random_bytes(32)));

        $clair = 'JBSWY3DPEHPK3PXP';
        $chiffre = $chiffreur->chiffrer($clair);

        self::assertNotSame($clair, $chiffre);
        self::assertSame($clair, $chiffreur->dechiffrer($chiffre));
    }

    public function testDeuxChiffrementsDuMemeSecretDonnentDesValeursDifferentes(): void
    {
        $chiffreur = new ChiffreurSecret(base64_encode(random_bytes(32)));

        $a = $chiffreur->chiffrer('meme-secret');
        $b = $chiffreur->chiffrer('meme-secret');

        self::assertNotSame($a, $b, 'Le nonce aléatoire doit rendre chaque chiffrement unique.');
        self::assertSame('meme-secret', $chiffreur->dechiffrer($a));
        self::assertSame('meme-secret', $chiffreur->dechiffrer($b));
    }
}
