<?php

declare(strict_types=1);

namespace App\Tests\PublicApi\Unit;

use App\PublicApi\Webhook\HostResolver;
use App\PublicApi\Webhook\UnsafeDestinationException;
use App\PublicApi\Webhook\WebhookDestinationGuard;
use App\PublicApi\Webhook\WebhookSender;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Anti-SSRF et signature des webhooks partenaires (spec API partenaire v1, §3.3, §3.6, critère 5).
 */
final class WebhookDestinationTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function internalAddresses(): iterable
    {
        yield 'privée 10/8' => ['https://10.0.0.1/hook'];
        yield 'bouclage' => ['https://127.0.0.1/hook'];
        yield 'bouclage IPv6' => ['https://[::1]/hook'];
        yield 'métadonnées du nuage' => ['https://169.254.169.254/latest/meta-data'];
        yield 'IPv4 mappée en IPv6' => ['https://[::ffff:127.0.0.1]/hook'];
        yield 'NAT64 vers une privée' => ['https://[64:ff9b::a00:1]/hook'];
        yield '6to4 vers une privée' => ['https://[2002:a00:1::1]/hook'];
        yield 'lien local IPv6' => ['https://[fe80::1]/hook'];
        yield 'NAT64 local 64:ff9b:1::/48' => ['https://[64:ff9b:1::808:808]/hook'];
        yield 'site local obsolète fec0::/10' => ['https://[fec0::1]/hook'];
        yield 'SIIT ::ffff:0:0:0/96' => ['https://[::ffff:0:808:808]/hook'];
    }

    #[DataProvider('internalAddresses')]
    public function testUneAdresseInterneEstRefusee(string $url): void
    {
        $this->expectException(UnsafeDestinationException::class);
        $this->guard(['10.0.0.1'])->pin($url);
    }

    /** Le NOM qui résout vers l'intérieur : c'est le cas qu'une liste d'IP littérales ne verrait pas. */
    public function testUnNomQuiResoutVersLInterieurEstRefuse(): void
    {
        foreach ([['10.0.0.1'], ['93.184.216.34', '169.254.169.254'], ['::ffff:10.0.0.1']] as $ips) {
            try {
                $this->guard($ips)->pin('https://partenaire.example/hook');
                self::fail('Résolution acceptée : '.implode(', ', $ips));
            } catch (UnsafeDestinationException) {
                self::addToAssertionCount(1);
            }
        }

        self::assertSame('93.184.216.34', $this->guard(['93.184.216.34'])->pin('https://partenaire.example/hook'), 'témoin : une adresse publique passe');
    }

    /**
     * La SAISIE refuse ce que l'envoi refuserait : les formes numériques qu'un résolveur lit comme une
     * adresse (`2130706433` = 127.0.0.1) et les zones IPv6. Témoin : un nom ordinaire passe.
     */
    public function testLaSaisieRefuseLesFormesNumeriquesEtLesZones(): void
    {
        foreach (['https://2130706433/hook', 'https://0x7f000001/hook', 'https://127.1/hook', 'https://0177.0.0.1/hook', 'https://[fe80::1%25eth0]/hook'] as $url) {
            try {
                $this->guard(['93.184.216.34'])->assertAcceptableUrl($url);
                self::fail('Saisie acceptée : '.$url);
            } catch (\Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame('partenaire.example', $this->guard(['93.184.216.34'])->assertAcceptableUrl('https://partenaire.example/hook'));
    }

    public function testSeulHttpsSansIdentifiantsEstAccepte(): void
    {
        foreach (['http://partenaire.example/hook', 'https://user:pass@partenaire.example/hook', 'ftp://partenaire.example'] as $url) {
            try {
                $this->guard(['93.184.216.34'])->pin($url);
                self::fail('URL acceptée : '.$url);
            } catch (UnsafeDestinationException) {
                self::addToAssertionCount(1);
            }
        }
    }

    /** La signature se vérifie sur le corps brut ; un octet changé la casse. */
    public function testLaSignatureSeVerifieEtLAlterationSeDetecte(): void
    {
        $sent = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$sent): MockResponse {
            $sent[] = $options;

            return new MockResponse('', ['http_code' => 204]);
        });
        $result = (new WebhookSender($http, $this->guard(['93.184.216.34'])))->send('https://partenaire.example/hook', 'whsec_test', '{"id":"e1"}', 'e1');

        self::assertTrue($result['ok']);
        self::assertCount(1, $sent);
        $headers = implode("\n", $sent[0]['headers']);
        self::assertMatchesRegularExpression('/Fluvia-Signature: t=(\d+),v1=([0-9a-f]{64})/', $headers);
        preg_match('/Fluvia-Signature: t=(\d+),v1=([0-9a-f]{64})/', $headers, $m);
        self::assertSame($m[2], hash_hmac('sha256', $m[1].'.{"id":"e1"}', 'whsec_test'), 'le partenaire retrouve la signature');
        self::assertNotSame($m[2], hash_hmac('sha256', $m[1].'.{"id":"e2"}', 'whsec_test'), 'un corps altéré ne la retrouve pas');
        self::assertStringContainsString('Idempotency-Key: e1', $headers);
        self::assertSame(['partenaire.example' => '93.184.216.34'], $sent[0]['resolve'], 'connexion épinglée sur l’adresse vérifiée');
        self::assertSame(0, $sent[0]['max_redirects']);
    }

    /** Destination refusée : AUCUN appel ne part. */
    public function testUneDestinationRefuseeNeRecoitRien(): void
    {
        $calls = 0;
        $http = new MockHttpClient(static function () use (&$calls): MockResponse {
            ++$calls;

            return new MockResponse('', ['http_code' => 200]);
        });
        $result = (new WebhookSender($http, $this->guard(['10.0.0.1'])))->send('https://partenaire.example/hook', 's', '{}', 'e');

        self::assertFalse($result['ok']);
        self::assertSame(0, $calls);
    }

    /**
     * La redirection n'est PAS suivie — prouvé avec le vrai client curl contre un serveur local qui
     * répond 302 vers une cible qui laisse une trace si on l'atteint.
     */
    public function testUneRedirectionNestPasSuivie(): void
    {
        $dir = sys_get_temp_dir().'/hook-'.bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir.'/router.php', '<?php if ($_SERVER["REQUEST_URI"] === "/cible") { touch(__DIR__."/atteinte"); exit; } header("Location: /cible", true, 302);');
        $port = random_int(20000, 40000);
        $server = proc_open([\PHP_BINARY, '-S', '127.0.0.1:'.$port, $dir.'/router.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        usleep(300000);

        try {
            $options = WebhookSender::options('127.0.0.1', '127.0.0.1', 's', '{}', 'e', time());
            $status = HttpClient::create()->request('POST', 'http://127.0.0.1:'.$port.'/hook', $options)->getStatusCode();

            self::assertSame(302, $status);
            self::assertFileDoesNotExist($dir.'/atteinte', 'la redirection a été suivie');
        } finally {
            proc_terminate($server);
            @unlink($dir.'/router.php');
            @unlink($dir.'/atteinte');
            @rmdir($dir);
        }
    }

    /** @param list<string> $ips */
    private function guard(array $ips): WebhookDestinationGuard
    {
        return new WebhookDestinationGuard(new class($ips) implements HostResolver {
            /** @param list<string> $ips */
            public function __construct(private readonly array $ips)
            {
            }

            public function resolve(string $host): array
            {
                return $this->ips;
            }
        });
    }
}
