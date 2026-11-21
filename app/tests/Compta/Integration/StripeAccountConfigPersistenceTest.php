<?php

declare(strict_types=1);

namespace App\Tests\Compta\Integration;

use App\Compta\Entity\ProfilExploitant;
use App\Compta\Entity\StripeAccountConfig;
use App\Compta\Enum\StripeKeyMode;
use App\Compta\Service\StripeKeyEncryptor;
use App\Tests\Compta\ComptaApiTestCase;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaValidator;

/**
 * G-1 : la clé Stripe d'un exploitant n'est jamais en clair en base ; une ligne par profil.
 * Les valeurs sont générées à l'exécution (aucun secret littéral).
 */
final class StripeAccountConfigPersistenceTest extends ComptaApiTestCase
{
    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine')->getManager();
    }

    private ?StripeKeyEncryptor $encryptor = null;

    private function encryptor(): StripeKeyEncryptor
    {
        // Clé maîtresse générée à l'exécution : ce test porte sur la colonne, pas sur le câblage du service.
        return $this->encryptor ??= new StripeKeyEncryptor(base64_encode(random_bytes(32)));
    }

    private function config(ProfilExploitant $profile, string $secretKey, ?string $webhookSecret = null): StripeAccountConfig
    {
        $config = (new StripeAccountConfig())
            ->setBusinessProfile($profile)
            ->setSecretKeyEncrypted($this->encryptor()->encrypt($secretKey))
            ->setKeyMode(StripeKeyMode::fromKey($secretKey) ?? StripeKeyMode::Test)
            ->setKeyUpdatedAt(new \DateTimeImmutable());
        if ($webhookSecret !== null) {
            $config->setWebhookSecretEncrypted($this->encryptor()->encrypt($webhookSecret))
                ->setWebhookSecretUpdatedAt(new \DateTimeImmutable());
        }

        return $config;
    }

    public function testMappingIsValidForTheEntity(): void
    {
        $errors = (new SchemaValidator($this->em()))->validateClass($this->em()->getClassMetadata(StripeAccountConfig::class));

        self::assertSame([], $errors);
    }

    public function testStoredValuesAreEncryptedAndDecryptToTheInput(): void
    {
        $secretKey = 'sk_test_' . bin2hex(random_bytes(12));
        $webhookSecret = 'whsec_' . bin2hex(random_bytes(12));
        $em = $this->em();
        $profile = $this->profilExploitant();
        $em->persist($this->config($profile, $secretKey, $webhookSecret));
        $em->flush();

        $row = $em->getConnection()->fetchAssociative(
            'SELECT secret_key_encrypted, webhook_secret_encrypted, key_mode FROM accounting_stripe_account_config WHERE business_profile_id = ?',
            [$profile->getId()->toBinary()],
        );

        // Témoin positif : la ligne existe et porte des valeurs non vides.
        self::assertIsArray($row);
        self::assertNotSame('', $row['secret_key_encrypted']);
        self::assertStringNotContainsString($secretKey, $row['secret_key_encrypted']);
        self::assertStringNotContainsString($webhookSecret, (string) $row['webhook_secret_encrypted']);
        self::assertSame($secretKey, $this->encryptor()->decrypt($row['secret_key_encrypted']));
        self::assertSame($webhookSecret, $this->encryptor()->decrypt((string) $row['webhook_secret_encrypted']));
        self::assertSame('test', $row['key_mode']);
    }

    public function testOnlyOneConfigurationPerProfile(): void
    {
        $em = $this->em();
        $profile = $this->profilExploitant();
        $em->persist($this->config($profile, 'sk_test_' . bin2hex(random_bytes(12))));
        $em->flush();

        // Témoin positif : la première ligne est bien là.
        self::assertSame(1, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM accounting_stripe_account_config'));

        $em->persist($this->config($profile, 'sk_live_' . bin2hex(random_bytes(12))));
        $this->expectException(UniqueConstraintViolationException::class);
        $em->flush();
    }

    public function testKeyModeFollowsThePrefix(): void
    {
        self::assertSame(StripeKeyMode::Test, StripeKeyMode::fromKey('sk_test_abc'));
        self::assertSame(StripeKeyMode::Test, StripeKeyMode::fromKey('rk_test_abc'));
        self::assertSame(StripeKeyMode::Live, StripeKeyMode::fromKey('sk_live_abc'));
        self::assertSame(StripeKeyMode::Live, StripeKeyMode::fromKey('rk_live_abc'));
        self::assertNull(StripeKeyMode::fromKey('pk_test_abc'));
        self::assertNull(StripeKeyMode::fromKey(''));
    }
}
