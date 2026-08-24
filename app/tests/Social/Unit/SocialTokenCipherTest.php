<?php

declare(strict_types=1);

namespace App\Tests\Social\Unit;

use App\Securite\Crypto\ChiffreurSecret;
use App\Social\Crypto\SocialTokenCipher;
use App\Social\Exception\SocialTokenCipherException;
use PHPUnit\Framework\TestCase;

/**
 * Coffre à jetons (SOC-1) — même patron que `App\Tests\Ocr\Unit\ChiffreurApiKeyOcrTest`, plus le
 * format versionné qui rendra la rotation de clé possible sans reconnexion.
 */
final class SocialTokenCipherTest extends TestCase
{
    private const KEY = 'Zm9vYmFyYmF6cXV4MTIzNDU2Nzg5MGFiY2RlZmc=';
    private const TOKEN = 'mastodon-access-token-abcdef123456';

    public function testAllerRetour(): void
    {
        $cipher = new SocialTokenCipher(self::KEY);

        self::assertSame(self::TOKEN, $cipher->decrypt($cipher->encrypt(self::TOKEN)));
    }

    public function testLeChiffreNeContientPasLeClair(): void
    {
        $cipher = new SocialTokenCipher(self::KEY);

        self::assertStringNotContainsString(self::TOKEN, $cipher->encrypt(self::TOKEN));
    }

    /**
     * Deux chiffrements du même jeton ne doivent pas se ressembler : un nonce par message. Sans cela,
     * lire la base suffirait à savoir que deux établissements ont connecté le même compte — et à
     * repérer, sur un même compte, le moment où le jeton a changé.
     */
    public function testDeuxChiffrementsDuMemeJetonDifferent(): void
    {
        $cipher = new SocialTokenCipher(self::KEY);

        self::assertNotSame($cipher->encrypt(self::TOKEN), $cipher->encrypt(self::TOKEN));
    }

    public function testUneAutreCleNeDechiffrePas(): void
    {
        $cipher = new SocialTokenCipher(self::KEY);
        $autre = new SocialTokenCipher('YWJjZGVmZ2hpamtsbW5vcHFyc3R1dnd4eXoxMjM0NTY=');

        $this->expectException(\RuntimeException::class);
        $autre->decrypt($cipher->encrypt(self::TOKEN));
    }

    public function testValeurCorrompueRefusee(): void
    {
        $cipher = new SocialTokenCipher(self::KEY);

        $this->expectException(\RuntimeException::class);
        $cipher->decrypt('pas-du-tout-un-chiffre');
    }

    // --- Format versionné : ce qui rendra la rotation possible sans reconnexion ---

    public function testLaValeurStockeePorteLaVersionDeLaCle(): void
    {
        $cipher = new SocialTokenCipher(self::KEY);

        self::assertStringStartsWith('v1:', $cipher->encrypt(self::TOKEN));
    }

    /**
     * Les lignes écrites par la première mouture de SOC-1, déjà fusionnée, n'ont pas de préfixe. Elles
     * doivent rester lisibles : sinon le format « qui évite une reprise de données » en imposerait une.
     */
    public function testUneValeurSansPrefixeResteLisible(): void
    {
        $ancienne = (new ChiffreurSecret(self::KEY))->chiffrer(self::TOKEN);
        self::assertStringStartsNotWith('v', $ancienne, 'Le format ancien ne porte pas de prefixe.');

        $cipher = new SocialTokenCipher(self::KEY);
        self::assertSame(self::TOKEN, $cipher->decrypt($ancienne));
        self::assertSame(1, $cipher->keyVersionOf($ancienne));
    }

    public function testVersionInconnueRefuseeExplicitement(): void
    {
        $cipher = new SocialTokenCipher(self::KEY);
        $chiffre = $cipher->encrypt(self::TOKEN);
        $faussementVersionne = 'v7:' . substr($chiffre, 3);

        // Réussir par accident avec la clé courante ferait croire une rotation terminée ; échouer
        // silencieusement ferait perdre le jeton sans qu'on sache lequel.
        $this->expectException(SocialTokenCipherException::class);
        $cipher->decrypt($faussementVersionne);
    }

    public function testLaVersionEstLisibleSansDechiffrer(): void
    {
        $cipher = new SocialTokenCipher(self::KEY);

        // C'est ce qui permettra de compter les lignes restant a rechiffrer, et de verifier la
        // repartition AVANT de retirer une cle de l'environnement.
        self::assertSame(1, $cipher->keyVersionOf($cipher->encrypt(self::TOKEN)));
    }

    public function testLeMessageDErreurNeContientPasLaValeur(): void
    {
        $cipher = new SocialTokenCipher(self::KEY);
        $chiffre = $cipher->encrypt(self::TOKEN);

        try {
            $cipher->decrypt('v7:' . substr($chiffre, 3));
            self::fail('Une version inconnue doit lever.');
        } catch (SocialTokenCipherException $e) {
            self::assertStringNotContainsString(substr($chiffre, 3), $e->getMessage());
            self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
        }
    }
}
