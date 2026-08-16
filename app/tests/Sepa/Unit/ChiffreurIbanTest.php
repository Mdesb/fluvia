<?php

declare(strict_types=1);

namespace App\Tests\Sepa\Unit;

use App\Sepa\Service\ChiffreurIban;
use PHPUnit\Framework\TestCase;

/**
 * Coffre IBAN réversible (`App\Sepa\Service\ChiffreurIban`, libsodium `crypto_secretbox`) : round-trip
 * chiffrer/déchiffrer, non-déterminisme du texte chiffré (nonce aléatoire), échec propre avec une
 * mauvaise clé ou une valeur invalide, jamais l'IBAN en clair dans le texte chiffré.
 */
final class ChiffreurIbanTest extends TestCase
{
    private const IBAN = 'FR7630006000011234567890189';

    public function testRoundTripChiffrerPuisDechiffrerRestitueLibanClair(): void
    {
        $chiffreur = new ChiffreurIban('cle-test-chiffrement-iban');

        $chiffre = $chiffreur->chiffrer(self::IBAN);
        $clair = $chiffreur->dechiffrer($chiffre);

        self::assertSame(self::IBAN, $clair);
    }

    public function testChiffrerNormaliseLibanMajusculesSansEspaces(): void
    {
        $chiffreur = new ChiffreurIban('cle-test-chiffrement-iban');

        $chiffre = $chiffreur->chiffrer('fr76 3000 6000 0112 3456 7890 189');
        $clair = $chiffreur->dechiffrer($chiffre);

        self::assertSame(self::IBAN, $clair);
    }

    public function testLeTexteChiffreNeContientJamaisLibanEnClair(): void
    {
        $chiffreur = new ChiffreurIban('cle-test-chiffrement-iban');

        $chiffre = $chiffreur->chiffrer(self::IBAN);

        self::assertStringNotContainsString(self::IBAN, $chiffre);
        self::assertStringNotContainsString('FR76', $chiffre);
    }

    public function testChiffrerEstNonDeterministeNonceAleatoire(): void
    {
        $chiffreur = new ChiffreurIban('cle-test-chiffrement-iban');

        $chiffreA = $chiffreur->chiffrer(self::IBAN);
        $chiffreB = $chiffreur->chiffrer(self::IBAN);

        self::assertNotSame($chiffreA, $chiffreB, 'Nonce aléatoire à chaque chiffrement : deux sorties différentes.');
        self::assertSame($chiffreur->dechiffrer($chiffreA), $chiffreur->dechiffrer($chiffreB), 'Mais déchiffrent vers le même IBAN.');
    }

    public function testDechiffrerAvecUneAutreCleEchoue(): void
    {
        $chiffreurA = new ChiffreurIban('cle-a');
        $chiffreurB = new ChiffreurIban('cle-b');

        $chiffre = $chiffreurA->chiffrer(self::IBAN);

        $this->expectException(\RuntimeException::class);
        $chiffreurB->dechiffrer($chiffre);
    }

    public function testDechiffrerUneValeurInvalideEchouePropremement(): void
    {
        $chiffreur = new ChiffreurIban('cle-test-chiffrement-iban');

        $this->expectException(\RuntimeException::class);
        $chiffreur->dechiffrer('valeur-non-chiffree-invalide');
    }
}
