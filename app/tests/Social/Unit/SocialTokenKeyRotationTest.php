<?php

declare(strict_types=1);

namespace App\Tests\Social\Unit;

use App\Social\Crypto\SocialTokenCipher;
use App\Social\Exception\SocialTokenCipherException;
use PHPUnit\Framework\TestCase;

/**
 * Rotation de clé du coffre social — la partie qui se prouve sans base.
 *
 * Le scénario complet est joué ici : une valeur écrite sous la génération 1 doit rester lisible après
 * que la génération 2 est devenue active, puis être rechiffrée. Sans ce test, on découvrirait le
 * défaut le jour de la rotation, sur des jetons réels, et sans moyen de revenir en arrière.
 */
final class SocialTokenKeyRotationTest extends TestCase
{
    private const V1 = 'Zm9vYmFyYmF6cXV4MTIzNDU2Nzg5MGFiY2RlZmc=';
    private const V2 = 'YWJjZGVmZ2hpamtsbW5vcHFyc3R1dnd4eXoxMjM0NTY=';
    private const TOKEN = 'mastodon-access-token-abcdef123456';

    public function testUneValeurDeLaGenerationPrecedenteResteLisible(): void
    {
        $ancien = new SocialTokenCipher(self::V1);
        $chiffreV1 = $ancien->encrypt(self::TOKEN);

        $nouveau = $this->cipherV2();

        self::assertSame(1, $nouveau->keyVersionOf($chiffreV1));
        self::assertSame(self::TOKEN, $nouveau->decrypt($chiffreV1));
    }

    public function testOnChiffreToujoursAvecLaCleActive(): void
    {
        $nouveau = $this->cipherV2();

        self::assertStringStartsWith('v2:', $nouveau->encrypt(self::TOKEN));
        self::assertSame(2, $nouveau->currentVersion());
        self::assertSame([2, 1], $nouveau->knownVersions());
    }

    public function testLeRechiffrementRendUneValeurALaCleActive(): void
    {
        $chiffreV1 = (new SocialTokenCipher(self::V1))->encrypt(self::TOKEN);
        $nouveau = $this->cipherV2();

        $rechiffre = $nouveau->reencrypt($chiffreV1);

        self::assertNotNull($rechiffre);
        self::assertStringStartsWith('v2:', $rechiffre);
        self::assertSame(self::TOKEN, $nouveau->decrypt($rechiffre));
    }

    /**
     * C'est ce qui rend la rotation reprenable : on peut relancer autant de fois qu'on veut, elle ne
     * retouche que ce qui reste. Le critère d'arrêt est un compteur qui tombe à zéro, pas une durée.
     */
    public function testRechiffrerCeQuiEstDejaALaCleActiveNeFaitRien(): void
    {
        $nouveau = $this->cipherV2();
        $dejaV2 = $nouveau->encrypt(self::TOKEN);

        self::assertNull($nouveau->reencrypt($dejaV2));
    }

    public function testUneValeurSansPrefixeEstRechiffrable(): void
    {
        // Les lignes écrites par la toute première mouture de SOC-1 n'ont pas de préfixe : la rotation
        // doit les rattraper, sinon le format « qui évite une reprise » en imposerait une.
        $ancienneSansPrefixe = (new \App\Securite\Crypto\ChiffreurSecret(self::V1))->chiffrer(self::TOKEN);
        $nouveau = $this->cipherV2();

        $rechiffre = $nouveau->reencrypt($ancienneSansPrefixe);

        self::assertNotNull($rechiffre);
        self::assertSame(self::TOKEN, $nouveau->decrypt($rechiffre));
    }

    /**
     * Le scénario qui coûte le plus cher : retirer l'ancienne clé avant d'avoir fini de rechiffrer.
     * Rien ne casse tant que personne ne publie — d'où l'exception explicite, qui nomme la version
     * manquante sans jamais montrer la valeur.
     */
    public function testCleRetireeTropTotDonneUneErreurExplicite(): void
    {
        $chiffreV1 = (new SocialTokenCipher(self::V1))->encrypt(self::TOKEN);
        // Génération 2 active, mais plus aucune clé v1 déclarée.
        $sansAncienne = new SocialTokenCipher(self::V2, null, 2);

        try {
            $sansAncienne->decrypt($chiffreV1);
            self::fail('Une version sans cle doit lever.');
        } catch (SocialTokenCipherException $e) {
            self::assertStringContainsString('version 1', $e->getMessage());
            self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
            self::assertStringNotContainsString(substr($chiffreV1, 3), $e->getMessage());
        }
    }

    /**
     * Une clé retirée qui revendique la version active ferait déchiffrer avec l'une et chiffrer avec
     * l'autre, sans qu'aucune erreur ne se produise avant que les jetons ne soient devenus illisibles.
     */
    public function testCleRetireeQuiRevendiqueLaVersionActiveRefuseDeDemarrer(): void
    {
        $this->expectException(SocialTokenCipherException::class);
        new SocialTokenCipher(self::V2, '2:' . self::V1, 2);
    }

    public function testCleRetireeMalFormeeRefuseDeDemarrer(): void
    {
        $this->expectException(SocialTokenCipherException::class);
        new SocialTokenCipher(self::V2, 'sans-numero-de-version', 2);
    }

    public function testAucuneCleRetireeEstUnEtatNormal(): void
    {
        // Tant qu'aucune rotation n'a eu lieu, il n'y a rien à déclarer — exiger une variable vide
        // serait une cérémonie sans contenu.
        $cipher = new SocialTokenCipher(self::V1, null);

        self::assertSame([1], $cipher->knownVersions());
        self::assertSame(self::TOKEN, $cipher->decrypt($cipher->encrypt(self::TOKEN)));
    }

    private function cipherV2(): SocialTokenCipher
    {
        return new SocialTokenCipher(self::V2, '1:' . self::V1, 2);
    }
}
