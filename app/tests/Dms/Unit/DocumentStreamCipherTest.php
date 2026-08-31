<?php

declare(strict_types=1);

namespace App\Tests\Dms\Unit;

use App\Dms\Crypto\DocumentStreamCipher;
use PHPUnit\Framework\TestCase;

/**
 * `DocumentStreamCipher` (plan-dms.md §0.2, RG-DMS-25/28) : round-trip chiffrement/déchiffrement sur
 * un flux de plusieurs blocs (>1 MiB, force >= 2 itérations de boucle), altération détectée (CA-12
 * couvert par le test de démarrage refusé).
 */
final class DocumentStreamCipherTest extends TestCase
{
    private const CLE_VALIDE = 'UYUxxa6tmLH6lYuj9iyPl070hdK6w0qfD2Hw9YpzqeQ=';

    public function testRoundTripSurFluxDePlusieursBlocs(): void
    {
        $cipher = new DocumentStreamCipher(self::CLE_VALIDE);

        // > 1 MiB (taille de bloc) pour forcer au moins 2 itérations de la boucle de chiffrement.
        $contenuClair = random_bytes(1_048_576 + 12_345);

        $source = fopen('php://temp', 'r+b');
        \assert($source !== false);
        fwrite($source, $contenuClair);
        rewind($source);

        $chiffre = fopen('php://temp', 'r+b');
        \assert($chiffre !== false);
        $hashCalcule = $cipher->encrypt($source, $chiffre);
        fclose($source);

        self::assertSame(hash('sha256', $contenuClair), $hashCalcule);

        rewind($chiffre);
        $tailleChiffree = fstat($chiffre)['size'];
        self::assertGreaterThan(\strlen($contenuClair), $tailleChiffree, 'Le flux chiffré porte au moins un en-tête + tags AEAD en plus du clair.');

        $destination = fopen('php://temp', 'r+b');
        \assert($destination !== false);
        $cipher->decrypt($chiffre, $destination);
        fclose($chiffre);

        rewind($destination);
        $contenuDechiffre = stream_get_contents($destination);
        fclose($destination);

        self::assertSame($contenuClair, $contenuDechiffre);
    }

    public function testAlterationDetecteeEchecAuthentification(): void
    {
        $cipher = new DocumentStreamCipher(self::CLE_VALIDE);

        $source = fopen('php://temp', 'r+b');
        \assert($source !== false);
        fwrite($source, random_bytes(2048));
        rewind($source);

        $chiffre = fopen('php://temp', 'r+b');
        \assert($chiffre !== false);
        $cipher->encrypt($source, $chiffre);
        fclose($source);

        // Altère un octet du corps chiffré (après l'en-tête de 24 octets) — doit être détecté, jamais
        // un déchiffrement silencieusement erroné.
        rewind($chiffre);
        $octets = stream_get_contents($chiffre);
        self::assertNotFalse($octets);
        $position = 30; // dans le corps chiffré, après l'en-tête.
        $octets[$position] = \chr((\ord($octets[$position]) + 1) % 256);

        $chiffreAltere = fopen('php://temp', 'r+b');
        \assert($chiffreAltere !== false);
        fwrite($chiffreAltere, $octets);
        rewind($chiffreAltere);

        $destination = fopen('php://temp', 'r+b');
        \assert($destination !== false);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/dms\.error\.encrypted_stream_tampered/');
        $cipher->decrypt($chiffreAltere, $destination);
    }

    /** CA-12 : démarrage refusé (exception au constructeur, pas au premier appel) si la clé est absente/mal formée. */
    public function testDemarrageRefuseSiCleAbsenteOuInvalide(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/dms\.error\.encryption_key_invalid/');
        new DocumentStreamCipher('');
    }

    public function testDemarrageRefuseSiCleMalFormee(): void
    {
        $this->expectException(\RuntimeException::class);
        // Chaîne base64 valide mais de la mauvaise longueur (pas 32 octets décodés).
        new DocumentStreamCipher(base64_encode('trop-court'));
    }
}
