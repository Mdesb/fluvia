<?php

declare(strict_types=1);

namespace App\Tests\Boutique\Integration;

use App\Boutique\Paiement\Stripe\StripeAccount;
use App\Boutique\Paiement\Stripe\StripeAccountResolver;
use App\Boutique\Paiement\Stripe\StripeAccountSource;
use App\Compta\Entity\ProfilExploitant;
use App\Compta\Entity\StripeAccountConfig;
use App\Compta\Enum\StripeKeyMode;
use App\Compta\Enum\TypeExploitant;
use App\Compta\Service\StripeKeyEncryptor;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Tests\Boutique\BoutiqueApiTestCase;
use App\Tests\Support\LogSpy;
use Psr\Log\NullLogger;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * Le compte Stripe d'un exploitant : le sien s'il l'a saisi, sinon le global, sinon aucun ; jamais un repli
 * silencieux sur un autre compte ; une seule lecture, sur la table de configuration, avant toute signature.
 * Toutes les valeurs sont générées à l'exécution.
 */
final class StripeAccountResolverTest extends BoutiqueApiTestCase
{
    private string $masterKey = '';
    private string $globalKey = '';
    private string $globalSecret = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->masterKey = base64_encode(random_bytes(32));
        $this->globalKey = 'sk_test_' . bin2hex(random_bytes(12));
        $this->globalSecret = 'whsec_' . bin2hex(random_bytes(12));
    }

    private function resolver(?string $globalKey = null, ?string $masterKey = null, ?string $globalSecret = null): StripeAccountResolver
    {
        return new StripeAccountResolver(
            $this->em(),
            new StripeKeyEncryptor($masterKey ?? $this->masterKey),
            new NullLogger(),
            $globalKey,
            'https://stripe.example.test/',
            $globalSecret,
        );
    }

    /** Profil A (fixtures ; le SIREN est le même que celui de B : on le désigne par son établissement principal) requalifié en exploitant privé. */
    private function profileA(TypeExploitant $type = TypeExploitant::GroupePrive): ProfilExploitant
    {
        $profile = $this->entite(ProfilExploitant::class, ['etablissementPrincipal' => $this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_A_NOM])])->setType($type);
        $this->em()->flush();

        return $profile;
    }

    /** Profil B : posé par les fixtures Boutique (exploitant privé). */
    private function profileB(): ProfilExploitant
    {
        return $this->entite(ProfilExploitant::class, ['etablissementPrincipal' => $this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_B_NOM])]);
    }

    private function store(ProfilExploitant $profile, string $key, ?string $secret = null, ?string $masterKey = null): void
    {
        $encryptor = new StripeKeyEncryptor($masterKey ?? $this->masterKey);
        $config = (new StripeAccountConfig())
            ->setBusinessProfile($profile)
            ->setSecretKeyEncrypted($encryptor->encrypt($key))
            ->setKeyMode(StripeKeyMode::fromKey($key) ?? StripeKeyMode::Test);
        if ($secret !== null) {
            $config->setWebhookSecretEncrypted($encryptor->encrypt($secret));
        }
        $this->em()->persist($config);
        $this->em()->flush();
    }

    public function testAProfileWithItsOwnKeyGetsItsOwnAccount(): void
    {
        $profile = $this->profileA();
        $key = 'sk_live_' . bin2hex(random_bytes(12));
        $secret = 'whsec_' . bin2hex(random_bytes(12));
        $this->store($profile, $key, $secret);

        $account = $this->resolver($this->globalKey)->forProfile($profile);

        self::assertInstanceOf(StripeAccount::class, $account);
        self::assertSame(StripeAccountSource::Profile, $account->source);
        self::assertSame($key, $account->secretKey());
        self::assertSame('https://stripe.example.test', $account->apiBase());
        self::assertSame((string) $profile->getId(), $account->profileId);
    }

    public function testWithoutAConfigurationTheGlobalKeyIsTheFallback(): void
    {
        $account = $this->resolver($this->globalKey, null, $this->globalSecret)->forProfile($this->profileA());

        self::assertSame(StripeAccountSource::Global, $account?->source);
        self::assertSame($this->globalKey, $account->secretKey());
        self::assertNull($account->profileId);
    }

    public function testNeitherConfigurationNorGlobalKeyGivesNoAccount(): void
    {
        $profile = $this->profileA();

        self::assertNull($this->resolver(null)->forProfile($profile));
        self::assertNull($this->resolver('pk_test_' . bin2hex(random_bytes(8)))->forProfile($profile), 'une clé publique globale ne vaut pas une clé');
        self::assertNull($this->resolver('')->forProfile($profile));
        // Témoin positif : une clé globale valide, elle, donne un compte.
        self::assertNotNull($this->resolver($this->globalKey)->forProfile($profile));
    }

    public function testADirectRegieHasNoStripeAccountEvenWithAConfiguration(): void
    {
        $profile = $this->profileA(TypeExploitant::RegieDirecte);
        $this->store($profile, 'sk_test_' . bin2hex(random_bytes(12)));

        self::assertNull($this->resolver($this->globalKey)->forProfile($profile));
        // Témoin positif : requalifié en privé, la même ligne est lue.
        self::assertSame(StripeAccountSource::Profile, $this->resolver($this->globalKey)->forProfile($this->profileA())?->source);
    }

    public function testTwoOperatorsGetTwoDistinctAccounts(): void
    {
        $keyA = 'sk_test_' . bin2hex(random_bytes(12));
        $keyB = 'sk_test_' . bin2hex(random_bytes(12));
        $this->store($this->profileA(), $keyA);
        $this->store($this->profileB(), $keyB);

        $resolver = $this->resolver($this->globalKey);
        self::assertSame($keyA, $resolver->forProfile($this->profileA())?->secretKey());
        self::assertSame($keyB, $resolver->forProfile($this->profileB())?->secretKey());
    }

    public function testAnUnreadableRowIsA503AndNeverTheGlobalAccount(): void
    {
        $profile = $this->profileA();
        $key = 'sk_test_' . bin2hex(random_bytes(12));
        $this->store($profile, $key);
        LogSpy::reset();

        try {
            // Autre clé maîtresse : la ligne existe mais ne se lit plus.
            $this->resolver($this->globalKey, base64_encode(random_bytes(32)))->forProfile($profile);
            self::fail('Un 503 était attendu : aucun repli sur la clé globale.');
        } catch (ServiceUnavailableHttpException $e) {
            self::assertSame(503, $e->getStatusCode());
            self::assertStringNotContainsString($key, $e->getMessage());
        }
        // Idem sans clé maîtresse du tout.
        try {
            $this->resolver($this->globalKey, '')->forProfile($profile);
            self::fail('Un 503 était attendu sans clé maîtresse.');
        } catch (ServiceUnavailableHttpException) {
        }
        // Témoin positif : avec la bonne clé maîtresse, la même ligne se lit.
        self::assertSame($key, $this->resolver($this->globalKey)->forProfile($profile)?->secretKey());
    }

    public function testAnUnreadableRowIsLoggedWithoutAnyValue(): void
    {
        $profile = $this->profileA();
        $key = 'sk_test_' . bin2hex(random_bytes(12));
        $this->store($profile, $key);
        $logger = new class () extends \Psr\Log\AbstractLogger {
            /** @var list<string> */
            public array $lines = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->lines[] = $level . ' ' . $message . ' ' . json_encode($context);
            }
        };
        $resolver = new StripeAccountResolver($this->em(), new StripeKeyEncryptor(base64_encode(random_bytes(32))), $logger, $this->globalKey);

        try {
            $resolver->forProfile($profile);
        } catch (ServiceUnavailableHttpException) {
        }

        self::assertCount(1, $logger->lines, 'Témoin positif : l\'événement est journalisé.');
        self::assertStringContainsString((string) $profile->getId(), $logger->lines[0]);
        self::assertStringNotContainsString($key, $logger->lines[0]);
        self::assertStringNotContainsString($this->globalKey, $logger->lines[0]);
    }

    public function testWebhookSecretLookup(): void
    {
        $secret = 'whsec_' . bin2hex(random_bytes(12));
        $withSecret = $this->profileA();
        $this->store($withSecret, 'sk_test_' . bin2hex(random_bytes(12)), $secret);
        $withoutSecret = $this->profileB();
        $this->store($withoutSecret, 'sk_test_' . bin2hex(random_bytes(12)));
        $resolver = $this->resolver($this->globalKey, null, $this->globalSecret);

        self::assertSame($this->globalSecret, $resolver->webhookSecretFor(null), 'adresse globale : secret global');
        self::assertSame($secret, $resolver->webhookSecretFor((string) $withSecret->getId()));
        self::assertSame('', $resolver->webhookSecretFor((string) $withoutSecret->getId()), 'exploitant sans secret');
        foreach ([\Symfony\Component\Uid\Uuid::v4()->toRfc4122(), '../x', '%00', str_repeat('a', 200), 'zzz', '', (string) $withSecret->getId() . 'x', (string) $withSecret->getId() . "\n"] as $unknown) {
            self::assertSame('', $resolver->webhookSecretFor($unknown), 'identifiant inconnu ou mal formé : « ' . substr($unknown, 0, 12) . ' »');
        }
        // Une autre clé maîtresse : le secret est illisible, donc vide (la signature échouera).
        self::assertSame('', $this->resolver($this->globalKey, base64_encode(random_bytes(32)))->webhookSecretFor((string) $withSecret->getId()));
    }

    public function testAnUnreadableWebhookSecretNeverBlocksPaymentButRefusesEveryNotification(): void
    {
        $profile = $this->profileA();
        $key = 'sk_test_' . bin2hex(random_bytes(12));
        $this->store($profile, $key);
        // Le secret de webhook est chiffré sous une autre clé maîtresse : illisible, alors que la clé se lit.
        $this->em()->getConnection()->executeStatement(
            'UPDATE accounting_stripe_account_config SET webhook_secret_encrypted = ? WHERE business_profile_id = ?',
            [(new StripeKeyEncryptor(base64_encode(random_bytes(32))))->encrypt('whsec_' . bin2hex(random_bytes(12))), $profile->getId()->toBinary()],
        );
        $resolver = $this->resolver($this->globalKey, null, $this->globalSecret);

        // Témoin positif : la clé se lit, le paiement passe (pas de 503).
        self::assertSame($key, $resolver->forProfile($profile)?->secretKey());
        // Et toute notification sur l'adresse du profil échoue à la signature (secret vide).
        self::assertSame('', $resolver->webhookSecretFor((string) $profile->getId()));
    }

    public function testWebhookSecretLookupReadsOnlyTheConfigurationTable(): void
    {
        $profile = $this->profileA();
        $this->store($profile, 'sk_test_' . bin2hex(random_bytes(12)), 'whsec_' . bin2hex(random_bytes(12)));
        $resolver = $this->resolver($this->globalKey);
        $holder = static::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(DebugDataHolder::class, $holder);

        $holder->reset();
        $resolver->webhookSecretFor((string) $profile->getId());
        $valid = array_column(array_merge(...array_values($holder->getData())), 'sql');
        // Témoin positif : le compteur compte, et la lecture valide fait exactement une requête.
        self::assertCount(1, $valid);
        self::assertStringContainsString('accounting_stripe_account_config', $valid[0]);
        self::assertStringNotContainsString('compta_profil_exploitant', $valid[0]);

        foreach ([\Symfony\Component\Uid\Uuid::v4()->toRfc4122(), '../x', 'zzz', str_repeat('a', 200)] as $other) {
            $holder->reset();
            $resolver->webhookSecretFor($other);
            $sql = array_column(array_merge([], ...array_values($holder->getData())), 'sql');
            self::assertLessThanOrEqual(1, \count($sql));
            foreach ($sql as $query) {
                self::assertStringContainsString('accounting_stripe_account_config', $query);
            }
        }
    }

    public function testTheAccountLeaksThroughNoChannel(): void
    {
        $key = 'sk_test_' . bin2hex(random_bytes(12));
        $secret = 'whsec_' . bin2hex(random_bytes(12));
        $account = new StripeAccount($key, 'https://stripe.example.test', StripeAccountSource::Profile, 'p1');

        ob_start();
        var_dump($account);
        print_r($account);
        $dumped = (string) ob_get_clean();
        self::assertStringContainsString('p1', $dumped, 'Témoin positif : le dump montre bien l\'objet.');
        foreach ([$key, $secret] as $value) {
            self::assertStringNotContainsString($value, $dumped);
            self::assertStringNotContainsString($value, (string) json_encode($account));
        }
        self::assertSame('{"source":"profile","profileId":"p1"}', json_encode($account), 'json_encode ne rend que les propriétés publiques, sans secret.');
        self::assertSame($key, $account->secretKey(), 'Témoin positif : l\'accesseur rend la clé.');

        try {
            serialize($account);
            self::fail('La sérialisation doit être refusée.');
        } catch (\LogicException $e) {
            self::assertStringNotContainsString($key, $e->getMessage());
        }

        $explode = static function (StripeAccount $a): never {
            throw new \RuntimeException('échec');
        };
        try {
            $explode($account);
        } catch (\RuntimeException $e) {
            self::assertStringNotContainsString($key, $e->getTraceAsString());
            self::assertStringNotContainsString($secret, $e->getTraceAsString());
            self::assertStringContainsString('StripeAccount', $e->getTraceAsString(), 'Témoin positif : la trace cite bien l\'argument.');
        }
    }
}
