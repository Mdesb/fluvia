<?php

declare(strict_types=1);

namespace App\Tests\Dms\Unit;

use App\Dms\Service\PublicLinkTokenGenerator;
use PHPUnit\Framework\TestCase;

/** RG-DMS-08 (§0.4 du plan) : jeton non énumérable, hachage déterministe, entropie suffisante. */
final class PublicLinkTokenGeneratorTest extends TestCase
{
    public function testJetonAUnFormatOpaqueNonEnumerable(): void
    {
        $generator = new PublicLinkTokenGenerator();
        $token = $generator->generateToken();

        // base64url sans padding, ~43 caractères pour 32 octets (256 bits) — jamais un compteur/UUID.
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{40,}$/', $token);
        self::assertStringNotContainsString('=', $token);
        self::assertStringNotContainsString('+', $token);
        self::assertStringNotContainsString('/', $token);
    }

    public function testHacherEstDeterministe(): void
    {
        $generator = new PublicLinkTokenGenerator();
        $token = $generator->generateToken();

        self::assertSame($generator->hash($token), $generator->hash($token));
        self::assertSame(64, \strlen($generator->hash($token)), 'sha256 hex = 64 caractères.');
    }

    public function testDeuxJetonsGeneresJamaisIdentiques(): void
    {
        $generator = new PublicLinkTokenGenerator();
        $tokens = [];
        for ($i = 0; $i < 50; ++$i) {
            $tokens[] = $generator->generateToken();
        }

        self::assertCount(50, array_unique($tokens), 'Entropie suffisante : aucune collision sur 50 générations.');
    }
}
