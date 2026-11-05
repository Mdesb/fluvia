<?php

declare(strict_types=1);

namespace App\Tests\Acces\Unit;

use App\Acces\Hardware\CredentialFormat;
use App\Acces\Hardware\CredentialNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Un même badge, quatre lecteurs réglés différemment : un seul identifiant stocké (#337). */
final class CredentialNormalizerTest extends TestCase
{
    /** @return iterable<string, array{string, CredentialFormat, string}> */
    public static function lectures(): iterable
    {
        yield 'hexa direct' => ['04A1B2C3', CredentialFormat::HexMsbFirst, '04A1B2C3'];
        yield 'hexa minuscule à deux-points' => ['04:a1:b2:c3', CredentialFormat::HexMsbFirst, '04A1B2C3'];
        yield 'hexa inversé' => ['C3 B2 A1 04', CredentialFormat::HexLsbFirst, '04A1B2C3'];
        yield 'décimal (octet nul de tête perdu)' => ['77705923', CredentialFormat::Decimal, '04A1B2C3'];
        yield 'décimal UID 7 octets' => ['1311768467294899695', CredentialFormat::Decimal, 'X'];
        yield 'brut (QR signé)' => [' QR-DEMO-0001 ', CredentialFormat::Raw, 'QR-DEMO-0001'];
        yield 'UID 7 octets inversé' => ['F6E5D4C3B2A104', CredentialFormat::HexLsbFirst, '04A1B2C3D4E5F6'];
    }

    #[DataProvider('lectures')]
    public function testRameneChaqueLectureALaFormeCanonique(string $brut, CredentialFormat $format, string $attendu): void
    {
        if ($attendu === 'X') {
            // 19 chiffres : au-delà d'un UID de 7 octets, refus plutôt qu'un nombre tronqué.
            $this->expectException(\InvalidArgumentException::class);
        }

        self::assertSame($attendu, (new CredentialNormalizer())->normalize($brut, $format));
    }

    public function testLeDecimalDUnUid7OctetsRetrouveSesOctetsNulsDeTete(): void
    {
        // 0x0004A1B2C3D4E5 : le décimal ne garde aucun zéro de tête, la normalisation les rend.
        self::assertSame('0004A1B2C3D4E5', (new CredentialNormalizer())->normalize((string) 0x0004A1B2C3D4E5, CredentialFormat::Decimal));
    }

    /** @return iterable<string, array{string, CredentialFormat}> */
    public static function lecturesIncoherentes(): iterable
    {
        yield 'hexa impair' => ['4A1B2C3', CredentialFormat::HexMsbFirst];
        yield 'hexa non hexa' => ['04A1B2CZ', CredentialFormat::HexMsbFirst];
        yield 'décimal non numérique' => ['04A1B2C3', CredentialFormat::Decimal];
        yield 'vide' => ['   ', CredentialFormat::Raw];
    }

    #[DataProvider('lecturesIncoherentes')]
    public function testRefuseUneLectureIncoherenteAvecLeFormatDeclare(string $brut, CredentialFormat $format): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new CredentialNormalizer())->normalize($brut, $format);
    }
}
