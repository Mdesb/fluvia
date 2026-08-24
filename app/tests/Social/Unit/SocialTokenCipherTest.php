<?php

declare(strict_types=1);

namespace App\Tests\Social\Unit;

use App\Social\Crypto\SocialTokenCipher;
use PHPUnit\Framework\TestCase;

/**
 * Coffre à jetons (SOC-1) — même patron que `App\Tests\Ocr\Unit\ChiffreurApiKeyOcrTest`.
 */
final class SocialTokenCipherTest extends TestCase
{
    private const KEY = 'Zm9vYmFyYmF6cXV4MTIzNDU2Nzg5MGFiY2RlZmc=';

    public function testAllerRetour(): void
    {
        $cipher = new SocialTokenCipher(self::KEY);
        $plain = 'mastodon-access-token-abcdef123456';

        self::assertSame($plain, $cipher->decrypt($cipher->encrypt($plain)));
    }

    public function testLeChiffreNeContientPasLeClair(): void
    {
        $cipher = new SocialTokenCipher(self::KEY);
        $plain = 'mastodon-access-token-abcdef123456';

        self::assertStringNotContainsString($plain, $cipher->encrypt($plain));
    }

    /**
     * Deux chiffrements du même jeton ne doivent pas se ressembler : un nonce par message. Sans cela,
     * lire la base suffirait à savoir que deux établissements ont connecté le même compte — et à
     * repérer, sur un même compte, le moment où le jeton a changé.
     */
    public function testDeuxChiffrementsDuMemeJetonDifferent(): void
    {
        $cipher = new SocialTokenCipher(self::KEY);
        $plain = 'mastodon-access-token-abcdef123456';

        self::assertNotSame($cipher->encrypt($plain), $cipher->encrypt($plain));
    }

    public function testUneAutreCleNeDechiffrePas(): void
    {
        $cipher = new SocialTokenCipher(self::KEY);
        $autre = new SocialTokenCipher('YWJjZGVmZ2hpamtsbW5vcHFyc3R1dnd4eXoxMjM0NTY=');

        $this->expectException(\RuntimeException::class);
        $autre->decrypt($cipher->encrypt('mastodon-access-token-abcdef123456'));
    }

    public function testValeurCorrompueRefusee(): void
    {
        $cipher = new SocialTokenCipher(self::KEY);

        $this->expectException(\RuntimeException::class);
        $cipher->decrypt('pas-du-tout-un-chiffre');
    }
}
