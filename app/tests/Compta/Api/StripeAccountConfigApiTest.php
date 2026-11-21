<?php

declare(strict_types=1);

namespace App\Tests\Compta\Api;

use App\Compta\Entity\ProfilExploitant;
use App\Compta\Entity\StripeAccountConfig;
use App\Compta\Enum\ReferentielComptable;
use App\Compta\Enum\StripeKeyMode;
use App\Compta\Enum\TypeExploitant;
use App\Compta\Service\StripeKeyEncryptor;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Compta\ComptaApiTestCase;
use App\Tests\Support\LogSpy;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Compte Stripe par exploitant (D3-B de #365), lecture et retrait : droit `payment.configure` (403),
 * cloisonnement par le profil exploitant (404 hors périmètre), et surtout G-6 : ni la clé, ni le secret
 * de webhook, ni leur version chiffrée ne sortent de l'API. Valeurs générées à l'exécution.
 */
final class StripeAccountConfigApiTest extends ComptaApiTestCase
{
    private string $secretKey = '';
    private string $webhookSecret = '';
    private string $otherSecretKey = '';

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine')->getManager();
    }

    /** Pose une configuration directement en base, chiffrée avec une clé maîtresse de test. */
    private function config(ProfilExploitant $profile, string $secretKey, ?string $webhookSecret): StripeAccountConfig
    {
        $encryptor = new StripeKeyEncryptor(base64_encode(random_bytes(32)));
        $config = (new StripeAccountConfig())
            ->setBusinessProfile($profile)
            ->setSecretKeyEncrypted($encryptor->encrypt($secretKey))
            ->setKeyMode(StripeKeyMode::Test)
            ->setKeyUpdatedAt(new \DateTimeImmutable());
        if ($webhookSecret !== null) {
            $config->setWebhookSecretEncrypted($encryptor->encrypt($webhookSecret))->setWebhookSecretUpdatedAt(new \DateTimeImmutable());
        }
        $this->em()->persist($config);
        $this->em()->flush();

        return $config;
    }

    private function profileOnB(): ProfilExploitant
    {
        $profile = (new ProfilExploitant())
            ->setType(TypeExploitant::GroupePrive)
            ->setReferentielComptable(ReferentielComptable::M57)
            ->setSiren('888888888')
            ->setEtablissementPrincipal($this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_B_NOM]));
        $this->em()->persist($profile);
        $this->em()->flush();

        return $profile;
    }

    /**
     * Exploitant privé (non régie) dont l'établissement PRINCIPAL est A : le seul qui puisse porter une clé.
     * Un établissement n'a qu'un profil principal (contrainte unique) : on requalifie celui des fixtures.
     */
    private function privateProfileOnA(): ProfilExploitant
    {
        $profile = $this->profilExploitant()->setType(TypeExploitant::GroupePrive);
        $this->em()->flush();

        return $profile;
    }

    private function masterKey(): string
    {
        return (string) ($_ENV['STRIPE_KEYS_ENCRYPTION_KEY'] ?? getenv('STRIPE_KEYS_ENCRYPTION_KEY'));
    }

    /** @param array<string, mixed> $entete @param array<string, mixed> $json */
    private function patch(\ApiPlatform\Symfony\Bundle\Test\Client $client, array $entete, string $id, array $json): void
    {
        $client->request('PATCH', '/api/stripe_account_configs/' . $id, [
            'headers' => $entete['headers'] + ['Content-Type' => 'application/merge-patch+json'],
            'json' => $json,
        ] + $entete);
    }

    /** @return array{0: string, 1: string} clé et secret relus en SQL puis déchiffrés avec la clé maîtresse */
    private function storedPlain(string $profileId): array
    {
        $row = $this->em()->getConnection()->fetchAssociative(
            'SELECT secret_key_encrypted AS k, webhook_secret_encrypted AS w FROM accounting_stripe_account_config WHERE business_profile_id = ?',
            [(new \Symfony\Component\Uid\Uuid($profileId))->toBinary()],
        );
        self::assertIsArray($row, 'La ligne doit exister en base.');
        $encryptor = new StripeKeyEncryptor($this->masterKey());

        return [$encryptor->decrypt($row['k']), $row['w'] === null ? '' : $encryptor->decrypt($row['w'])];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->secretKey = 'sk_test_' . bin2hex(random_bytes(12));
        $this->webhookSecret = 'whsec_' . bin2hex(random_bytes(12));
        $this->otherSecretKey = 'sk_live_' . bin2hex(random_bytes(12));
    }

    /** @return array{0: \ApiPlatform\Symfony\Bundle\Test\Client, 1: array<string, mixed>} */
    private function lecteurSurA(): array
    {
        $client = static::createClient();
        $token = $this->jeton($client, SocleFixtures::LECTEUR_EMAIL, SocleFixtures::LECTEUR_MDP);

        return [$client, ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $this->idEtablissement(SocleFixtures::ETAB_A_NOM)]]];
    }

    public function testWithoutThePermissionReadAndDeleteAreForbidden(): void
    {
        $config = $this->config($this->profilExploitant(), $this->secretKey, $this->webhookSecret);
        [$client, $entete] = $this->lecteurSurA();

        $client->request('GET', '/api/stripe_account_configs', $entete);
        self::assertResponseStatusCodeSame(403);
        $client->request('GET', '/api/stripe_account_configs/' . $config->getId(), $entete);
        self::assertResponseStatusCodeSame(403);
        $client->request('DELETE', '/api/stripe_account_configs/' . $config->getId(), $entete);
        self::assertResponseStatusCodeSame(403);

        // Témoin positif : la ligne est toujours là.
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM accounting_stripe_account_config'));
    }

    public function testAConfigurationOutsideThePerimeterIsNotFoundAndNotListed(): void
    {
        $own = $this->config($this->profilExploitant(), $this->secretKey, null);
        $foreign = $this->config($this->profileOnB(), $this->otherSecretKey, $this->webhookSecret);
        [$client, $entete] = $this->adminSurA();

        $client->request('GET', '/api/stripe_account_configs', $entete);
        self::assertResponseIsSuccessful();
        $ids = array_column($client->getResponse()->toArray()['member'], 'id');
        self::assertSame([(string) $own->getId()], $ids, 'Seule la ligne du profil de l\'établissement actif est listée.');

        $client->request('GET', '/api/stripe_account_configs/' . $foreign->getId(), $entete);
        self::assertResponseStatusCodeSame(404);
        $client->request('DELETE', '/api/stripe_account_configs/' . $foreign->getId(), $entete);
        self::assertResponseStatusCodeSame(404);

        // Témoin positif : la ligne étrangère existe, et n'a pas été touchée.
        self::assertSame(2, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM accounting_stripe_account_config'));
    }

    public function testNeitherTheKeyNorTheSecretNorTheirEncryptedFormAreReturned(): void
    {
        $config = $this->config($this->profilExploitant(), $this->secretKey, $this->webhookSecret);
        $encrypted = $this->em()->getConnection()->fetchAssociative('SELECT secret_key_encrypted AS k, webhook_secret_encrypted AS w FROM accounting_stripe_account_config');
        // Témoin positif : les valeurs chiffrées existent bien en base.
        self::assertNotEmpty($encrypted['k']);
        self::assertNotEmpty($encrypted['w']);
        [$client, $entete] = $this->adminSurA();

        foreach (['/api/stripe_account_configs', '/api/stripe_account_configs/' . $config->getId()] as $url) {
            $client->request('GET', $url, $entete);
            self::assertResponseIsSuccessful();
            $body = $client->getResponse()->getContent();

            foreach ([$this->secretKey, $this->webhookSecret, $encrypted['k'], $encrypted['w']] as $forbidden) {
                self::assertStringNotContainsString($forbidden, $body);
            }
            foreach (['secretKeyEncrypted', 'secretKeyPlain', 'webhookSecretEncrypted', 'webhookSecretPlain'] as $name) {
                self::assertStringNotContainsString($name, $body);
            }
        }

        $item = $client->request('GET', '/api/stripe_account_configs/' . $config->getId(), $entete)->toArray();
        self::assertTrue($item['hasSecretKey']);
        self::assertTrue($item['hasWebhookSecret']);
        self::assertSame('test', $item['keyMode']);
    }

    public function testAnAttachedNonPrincipalEstablishmentCanNeitherPatchNorDeleteTheProfilesConfiguration(): void
    {
        $profileOnB = $this->profileOnB();
        // Rattaché à A sans en être le principal : le droit de modifier la clé reste au seul principal.
        $profileOnB->addEtablissementRattache($this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_A_NOM]));
        $this->em()->flush();
        $foreign = $this->config($profileOnB, $this->otherSecretKey, $this->webhookSecret);
        $own = $this->config($this->privateProfileOnA(), $this->secretKey, null);
        [$client, $entete] = $this->adminSurA();
        $before = $this->em()->getConnection()->fetchAssociative('SELECT * FROM accounting_stripe_account_config WHERE id = ?', [$foreign->getId()->toBinary()]);

        $this->patch($client, $entete, (string) $foreign->getId(), ['secretKeyPlain' => 'sk_live_' . bin2hex(random_bytes(12))]);
        self::assertResponseStatusCodeSame(404);
        $client->request('DELETE', '/api/stripe_account_configs/' . $foreign->getId(), $entete);
        self::assertResponseStatusCodeSame(404);

        self::assertSame($before, $this->em()->getConnection()->fetchAssociative('SELECT * FROM accounting_stripe_account_config WHERE id = ?', [$foreign->getId()->toBinary()]), 'rien n\'a été modifié ni supprimé');

        // Témoin positif : depuis son principal, le même PATCH puis le même DELETE passent.
        $this->patch($client, $entete, (string) $own->getId(), ['webhookSecretPlain' => $this->webhookSecret]);
        self::assertResponseIsSuccessful();
        $client->request('DELETE', '/api/stripe_account_configs/' . $own->getId(), $entete);
        self::assertResponseStatusCodeSame(204);
    }

    public function testDeleteWithinThePerimeterRemovesTheRow(): void
    {
        $config = $this->config($this->profilExploitant(), $this->secretKey, null);
        [$client, $entete] = $this->adminSurA();

        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM accounting_stripe_account_config'));
        $client->request('DELETE', '/api/stripe_account_configs/' . $config->getId(), $entete);

        self::assertResponseStatusCodeSame(204);
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM accounting_stripe_account_config'));
    }

    public function testCreationEncryptsBothValuesAndReturnsNoSecret(): void
    {
        $profile = $this->privateProfileOnA();
        [$client, $entete] = $this->adminSurA();

        $response = $client->request('POST', '/api/stripe_account_configs', $entete + ['json' => [
            'businessProfile' => '/api/profil_exploitants/' . $profile->getId(),
            'secretKeyPlain' => $this->secretKey,
            'webhookSecretPlain' => $this->webhookSecret,
        ]]);

        self::assertResponseStatusCodeSame(201);
        $body = $response->getContent();
        $json = $response->toArray();
        self::assertTrue($json['hasSecretKey']);
        self::assertTrue($json['hasWebhookSecret']);
        self::assertSame('test', $json['keyMode']);
        self::assertStringNotContainsString($this->secretKey, $body);
        self::assertStringNotContainsString($this->webhookSecret, $body);
        self::assertStringNotContainsString('Plain', $body);
        self::assertStringNotContainsString('Encrypted', $body);

        // G-1 : en base, ni l'un ni l'autre n'est en clair ; ils se déchiffrent en la saisie.
        $row = $this->em()->getConnection()->fetchAssociative('SELECT secret_key_encrypted AS k, webhook_secret_encrypted AS w FROM accounting_stripe_account_config');
        self::assertNotEmpty($row['k']);
        self::assertStringNotContainsString($this->secretKey, $row['k']);
        self::assertStringNotContainsString($this->webhookSecret, $row['w']);
        self::assertSame([$this->secretKey, $this->webhookSecret], $this->storedPlain((string) $profile->getId()));
    }

    public function testLiveKeyGivesLiveModeAndRestrictedKeyIsAccepted(): void
    {
        $restricted = 'rk_live_' . bin2hex(random_bytes(12));
        $profile = $this->privateProfileOnA();
        [$client, $entete] = $this->adminSurA();

        $json = $client->request('POST', '/api/stripe_account_configs', $entete + ['json' => [
            'businessProfile' => '/api/profil_exploitants/' . $profile->getId(),
            'secretKeyPlain' => $restricted,
        ]])->toArray();

        self::assertResponseStatusCodeSame(201);
        self::assertSame('live', $json['keyMode']);
        self::assertFalse($json['hasWebhookSecret']);
        self::assertSame([$restricted, ''], $this->storedPlain((string) $profile->getId()));
    }

    public function testPatchReplacesOneValueAndLeavesTheOtherIntact(): void
    {
        $profile = $this->privateProfileOnA();
        [$client, $entete] = $this->adminSurA();
        $id = $client->request('POST', '/api/stripe_account_configs', $entete + ['json' => [
            'businessProfile' => '/api/profil_exploitants/' . $profile->getId(),
            'secretKeyPlain' => $this->secretKey,
            'webhookSecretPlain' => $this->webhookSecret,
        ]])->toArray()['id'];

        // Remplace le seul secret de webhook : la clé reste intacte (et son mode).
        $newSecret = 'whsec_' . bin2hex(random_bytes(12));
        $this->patch($client, $entete, $id, ['webhookSecretPlain' => $newSecret]);
        self::assertResponseIsSuccessful();
        self::assertSame([$this->secretKey, $newSecret], $this->storedPlain((string) $profile->getId()));

        // Remplace la seule clé (par une clé live) : le secret reste intact.
        $newKey = 'sk_live_' . bin2hex(random_bytes(12));
        $this->patch($client, $entete, $id, ['secretKeyPlain' => $newKey]);
        self::assertResponseIsSuccessful();
        $body = $client->getResponse()->getContent();
        self::assertSame([$newKey, $newSecret], $this->storedPlain((string) $profile->getId()));
        self::assertSame('live', $client->getResponse()->toArray()['keyMode']);
        self::assertNotEmpty($body, 'sinon l\'absence des secrets ne prouverait rien');
        self::assertStringNotContainsString($newKey, $body);
        self::assertStringNotContainsString($newSecret, $body);
    }

    public function testWithoutThePermissionWritesAreForbidden(): void
    {
        $profile = $this->privateProfileOnA();
        $config = $this->config($profile, $this->secretKey, null);
        [$client, $entete] = $this->lecteurSurA();

        $client->request('POST', '/api/stripe_account_configs', $entete + ['json' => [
            'businessProfile' => '/api/profil_exploitants/' . $profile->getId(),
            'secretKeyPlain' => $this->otherSecretKey,
        ]]);
        self::assertResponseStatusCodeSame(403);
        $this->patch($client, $entete, (string) $config->getId(), ['secretKeyPlain' => $this->otherSecretKey]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testAProfileOutsideThePerimeterIsRefusedEvenIfItCoversTheActiveEstablishment(): void
    {
        $foreign = $this->profileOnB();
        // Rattaché à A sans en être le principal : la clé engage les encaissements de TOUS ses établissements.
        $foreign->addEtablissementRattache($this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_A_NOM]));
        $this->em()->flush();
        [$client, $entete] = $this->adminSurA();

        $client->request('POST', '/api/stripe_account_configs', $entete + ['json' => [
            'businessProfile' => '/api/profil_exploitants/' . $foreign->getId(),
            'secretKeyPlain' => $this->secretKey,
        ]]);

        // 400 et non 404 : le profil du corps est résolu par son IRI, à travers l'extension de périmètre de
        // `ProfilExploitant` (principal = établissement actif) ; un profil hors périmètre est donc « introuvable »,
        // exactement comme un profil qui n'existe pas (aucun oracle). Le 404 du processeur double ce filtre.
        self::assertResponseStatusCodeSame(400);
        $errorBody = $client->getResponse()->getContent(false);
        self::assertNotEmpty($errorBody, 'sinon l\'absence du secret ne prouverait rien');
        self::assertStringNotContainsString($this->secretKey, $errorBody);
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM accounting_stripe_account_config'));
        // Témoin positif : le même appel sur un profil du périmètre réussit.
        $client->request('POST', '/api/stripe_account_configs', $entete + ['json' => [
            'businessProfile' => '/api/profil_exploitants/' . $this->privateProfileOnA()->getId(),
            'secretKeyPlain' => $this->secretKey,
        ]]);
        self::assertResponseStatusCodeSame(201);
    }

    public function testADirectRegieCannotHoldAKey(): void
    {
        [$client, $entete] = $this->adminSurA();

        $client->request('POST', '/api/stripe_account_configs', $entete + ['json' => [
            'businessProfile' => '/api/profil_exploitants/' . $this->idProfilExploitant(),
            'secretKeyPlain' => $this->secretKey,
        ]]);

        self::assertResponseStatusCodeSame(422);
        $errorBody = $client->getResponse()->getContent(false);
        self::assertNotEmpty($errorBody, 'sinon l\'absence du secret ne prouverait rien');
        self::assertStringNotContainsString($this->secretKey, $errorBody);
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM accounting_stripe_account_config'));
    }

    /** @return iterable<string, array{0: array<string, string>, 1: string}> */
    public static function invalidFormats(): iterable
    {
        yield 'clé publique' => [['secretKeyPlain' => 'pk_test_' . 'ab12cd34ef56gh78'], 'pk_test_ab12cd34ef56gh78'];
        yield 'clé sans corps' => [['secretKeyPlain' => 'sk_live_'], 'sk_live_'];
        yield 'clé de 300 caractères' => [['secretKeyPlain' => 'sk_test_' . str_repeat('a', 292)], str_repeat('a', 292)];
        yield 'clé avec espace' => [['secretKeyPlain' => 'sk_test_ab12 cd34ef56'], 'ab12 cd34ef56'];
        yield 'secret de webhook invalide' => [['secretKeyPlain' => 'sk_test_ab12cd34ef56', 'webhookSecretPlain' => 'whsec_???'], 'whsec_???'];
        yield 'secret sans préfixe' => [['secretKeyPlain' => 'sk_test_ab12cd34ef56', 'webhookSecretPlain' => 'abcdefghijkl'], 'abcdefghijkl'];
    }

    /** @param array<string, string> $payload */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidFormats')]
    public function testInvalidFormatsAreRefusedWithoutEchoingTheValue(array $payload, string $submitted): void
    {
        $profile = $this->privateProfileOnA();
        [$client, $entete] = $this->adminSurA();

        $client->request('POST', '/api/stripe_account_configs', $entete + ['json' => [
            'businessProfile' => '/api/profil_exploitants/' . $profile->getId(),
        ] + $payload]);

        self::assertResponseStatusCodeSame(422);
        $body = $client->getResponse()->getContent(false);
        self::assertStringNotContainsString($submitted, $body, 'La réponse 422 ne renvoie jamais la valeur saisie.');
        self::assertStringContainsString('violations', $body, 'Témoin positif : la réponse porte bien un message de violation.');
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM accounting_stripe_account_config'));
    }

    public function testASecretWithoutAKeyIsRefused(): void
    {
        $profile = $this->privateProfileOnA();
        [$client, $entete] = $this->adminSurA();

        $client->request('POST', '/api/stripe_account_configs', $entete + ['json' => [
            'businessProfile' => '/api/profil_exploitants/' . $profile->getId(),
            'webhookSecretPlain' => $this->webhookSecret,
        ]]);

        self::assertResponseStatusCodeSame(422);
        $errorBody = $client->getResponse()->getContent(false);
        self::assertNotEmpty($errorBody, 'sinon l\'absence du secret ne prouverait rien');
        self::assertStringNotContainsString($this->webhookSecret, $errorBody);
    }

    public function testASecondConfigurationForTheSameProfileIsAConflict(): void
    {
        $profile = $this->privateProfileOnA();
        [$client, $entete] = $this->adminSurA();
        $payload = $entete + ['json' => [
            'businessProfile' => '/api/profil_exploitants/' . $profile->getId(),
            'secretKeyPlain' => $this->secretKey,
        ]];

        $client->request('POST', '/api/stripe_account_configs', $payload);
        self::assertResponseStatusCodeSame(201);
        $client->request('POST', '/api/stripe_account_configs', $payload);

        self::assertResponseStatusCodeSame(409);
        $errorBody = $client->getResponse()->getContent(false);
        self::assertNotEmpty($errorBody, 'sinon l\'absence du secret ne prouverait rien');
        self::assertStringNotContainsString($this->secretKey, $errorBody);
    }

    public function testWithoutMasterKeyTheKeyIsRefusedClearlyAndNothingIsStored(): void
    {
        $profile = $this->privateProfileOnA();
        [$client, $entete] = $this->adminSurA();
        $master = $this->masterKey();
        $_ENV['STRIPE_KEYS_ENCRYPTION_KEY'] = '';
        putenv('STRIPE_KEYS_ENCRYPTION_KEY=');

        try {
            $client->request('POST', '/api/stripe_account_configs', $entete + ['json' => [
                'businessProfile' => '/api/profil_exploitants/' . $profile->getId(),
                'secretKeyPlain' => $this->secretKey,
            ]]);
            $status = $client->getResponse()->getStatusCode();
            $body = $client->getResponse()->getContent(false);
        } finally {
            $_ENV['STRIPE_KEYS_ENCRYPTION_KEY'] = $master;
            putenv('STRIPE_KEYS_ENCRYPTION_KEY=' . $master);
        }

        self::assertSame(503, $status);
        self::assertStringContainsString('chiffrement', $body);
        self::assertStringNotContainsString($this->secretKey, $body);
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM accounting_stripe_account_config'));

        // Témoin positif : avec la clé maîtresse, la même requête réussit.
        $client->request('POST', '/api/stripe_account_configs', $entete + ['json' => [
            'businessProfile' => '/api/profil_exploitants/' . $profile->getId(),
            'secretKeyPlain' => $this->secretKey,
        ]]);
        self::assertResponseStatusCodeSame(201);
    }

    public function testNoLogLineEverContainsTheKeyOrTheSecret(): void
    {
        $profile = $this->privateProfileOnA();
        [$client, $entete] = $this->adminSurA();
        LogSpy::reset();
        $newKey = 'sk_test_' . bin2hex(random_bytes(12));

        $json = ['businessProfile' => '/api/profil_exploitants/' . $profile->getId(), 'secretKeyPlain' => $this->secretKey, 'webhookSecretPlain' => $this->webhookSecret];
        $id = $client->request('POST', '/api/stripe_account_configs', $entete + ['json' => $json])->toArray()['id'];      // création
        $this->patch($client, $entete, $id, ['secretKeyPlain' => $newKey]);                                                // remplacement
        $client->request('POST', '/api/stripe_account_configs', $entete + ['json' => $json]);                             // 409
        $this->patch($client, $entete, $id, ['secretKeyPlain' => 'pk_test_' . $this->secretKey]);                           // 422

        $dump = LogSpy::dump();
        // Témoin positif : le journal a bien enregistré les erreurs (409 et 422) de ce scénario.
        self::assertNotSame('', $dump);
        self::assertStringContainsString('ConflictHttpException', $dump, 'Le 409 du scénario est passé par le journal.');
        self::assertStringContainsString('Authenticator successful', $dump, 'Le journal complet (debug compris) est capté.');
        foreach ([$this->secretKey, $this->webhookSecret, $newKey] as $secret) {
            self::assertStringNotContainsString($secret, $dump);
        }
    }
}
