<?php

declare(strict_types=1);

namespace App\Tests\Compta\Unit;

use App\Compta\Service\StripeKeyEncryptionUnavailableException;
use App\Compta\Service\StripeKeyEncryptor;
use PHPUnit\Framework\TestCase;

/**
 * Chiffreur des clés Stripe des exploitants (G-1) : aller-retour, nonce, mauvaise clé maîtresse.
 * Tous les secrets sont générés à l'exécution : aucune valeur littérale.
 */
final class StripeKeyEncryptorTest extends TestCase
{
    private static function masterKey(): string
    {
        return base64_encode(random_bytes(32));
    }

    private static function fakeStripeKey(): string
    {
        return 'sk_test_' . bin2hex(random_bytes(12));
    }

    public function testRoundTripRestoresThePlainValue(): void
    {
        $encryptor = new StripeKeyEncryptor(self::masterKey());
        $plain = self::fakeStripeKey();

        self::assertSame($plain, $encryptor->decrypt($encryptor->encrypt($plain)));
    }

    public function testTwoEncryptionsOfTheSamePlainValueDiffer(): void
    {
        $encryptor = new StripeKeyEncryptor(self::masterKey());
        $plain = self::fakeStripeKey();

        $first = $encryptor->encrypt($plain);
        $second = $encryptor->encrypt($plain);

        self::assertNotSame($first, $second);
        self::assertSame($plain, $encryptor->decrypt($first));
        self::assertSame($plain, $encryptor->decrypt($second));
    }

    public function testCipherTextDoesNotContainThePlainValue(): void
    {
        $encryptor = new StripeKeyEncryptor(self::masterKey());
        $plain = self::fakeStripeKey();

        $cipher = $encryptor->encrypt($plain);

        self::assertStringNotContainsString($plain, $cipher);
        // Témoin positif : le chiffré existe et se déchiffre.
        self::assertNotSame('', $cipher);
        self::assertSame($plain, $encryptor->decrypt($cipher));
    }

    public function testAnotherMasterKeyCannotDecrypt(): void
    {
        $cipher = (new StripeKeyEncryptor(self::masterKey()))->encrypt(self::fakeStripeKey());

        $this->expectException(\RuntimeException::class);
        (new StripeKeyEncryptor(self::masterKey()))->decrypt($cipher);
    }

    public function testCorruptedValueFailsCleanly(): void
    {
        $encryptor = new StripeKeyEncryptor(self::masterKey());

        $this->expectException(\RuntimeException::class);
        $encryptor->decrypt('not-a-cipher-text');
    }

    public function testExceptionMessageNeverEchoesTheValue(): void
    {
        $encryptor = new StripeKeyEncryptor(self::masterKey());
        $plain = self::fakeStripeKey();
        $cipher = (new StripeKeyEncryptor(self::masterKey()))->encrypt($plain);

        try {
            $encryptor->decrypt($cipher);
            self::fail('Un déchiffrement avec une autre clé aurait dû échouer.');
        } catch (\RuntimeException $e) {
            self::assertStringNotContainsString($plain, $e->getMessage());
            self::assertStringNotContainsString($cipher, $e->getMessage());
        }
    }

    public function testAMalformedMasterKeyIsUnavailableAndNeverSilentlyDerived(): void
    {
        $plain = self::fakeStripeKey();
        $malformed = [
            'not base64' => 'not*base64*at*all',
            'too short (16 bytes)' => base64_encode(random_bytes(16)),
            'too long (33 bytes)' => base64_encode(random_bytes(33)),
            'raw text' => 'a-plain-text-passphrase-of-32-chr',
        ];
        foreach ($malformed as $label => $value) {
            $encryptor = new StripeKeyEncryptor($value);
            self::assertFalse($encryptor->isAvailable(), $label);
            foreach ([static fn () => $encryptor->encrypt($plain), static fn () => $encryptor->decrypt('anything')] as $use) {
                try {
                    $use();
                    self::fail($label . ' : l\'usage doit échouer.');
                } catch (StripeKeyEncryptionUnavailableException $e) {
                    self::assertStringNotContainsString($value, $e->getMessage(), $label);
                    self::assertStringNotContainsString($plain, $e->getMessage(), $label);
                }
            }
        }
        // Témoin positif : 32 octets en base64 strict.
        self::assertTrue((new StripeKeyEncryptor(self::masterKey()))->isAvailable());
    }

    public function testWithoutMasterKeyUsageFailsClearlyAndLeaksNothing(): void
    {
        $plain = self::fakeStripeKey();

        foreach ([null, ''] as $missing) {
            $encryptor = new StripeKeyEncryptor($missing);
            self::assertFalse($encryptor->isAvailable());

            foreach ([
                static fn () => $encryptor->encrypt($plain),
                static fn () => $encryptor->decrypt('anything'),
            ] as $use) {
                try {
                    $use();
                    self::fail('Sans clé maîtresse, l\'usage doit échouer.');
                } catch (StripeKeyEncryptionUnavailableException $e) {
                    self::assertStringContainsString('STRIPE_KEYS_ENCRYPTION_KEY', $e->getMessage());
                    self::assertStringNotContainsString($plain, $e->getMessage());
                    self::assertStringNotContainsString($plain, $e->getTraceAsString());
                }
            }
        }

        // Témoin positif : avec une clé, le même service est disponible.
        self::assertTrue((new StripeKeyEncryptor(self::masterKey()))->isAvailable());
    }
}
