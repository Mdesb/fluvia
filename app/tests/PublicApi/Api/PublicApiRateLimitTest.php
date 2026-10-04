<?php

declare(strict_types=1);

namespace App\Tests\PublicApi\Api;

use App\Tests\PublicApi\PublicApiTestCase;

/**
 * 120 requêtes par minute et par clé ; la 121ᵉ reçoit 429 — y compris quand les 120 premières viennent
 * de plusieurs processus (spec API partenaire v1, critère d'acceptation n°4).
 */
final class PublicApiRateLimitTest extends PublicApiTestCase
{
    public function testLaCentVingtEtUniemeRequeteRecoit429(): void
    {
        ['secret' => $secret] = $this->issueKey($this->createApplication()['id']);
        $client = static::createClient();
        $options = ['headers' => ['Authorization' => 'Bearer '.$secret]];

        for ($i = 1; $i <= 120; ++$i) {
            $response = $client->request('GET', '/v1/me', $options);
            self::assertSame(200, $response->getStatusCode(), sprintf('requête n°%d', $i));
        }
        self::assertSame('120', $response->getHeaders()['ratelimit-limit'][0]);
        self::assertSame('0', $response->getHeaders()['ratelimit-remaining'][0]);

        $refused = $client->request('GET', '/v1/me', $options);
        self::assertSame(429, $refused->getStatusCode());
        $headers = $refused->getHeaders(false);
        self::assertSame('0', $headers['ratelimit-remaining'][0]);
        self::assertGreaterThanOrEqual(1, (int) $headers['retry-after'][0]);

        // Le compteur est EN BASE — témoin que le pool n'est ni APCu ni un fichier du conteneur.
        $rows = (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM cache_items');
        self::assertGreaterThan(0, $rows, 'la fenêtre de la limite doit vivre dans la table `cache_items`');
    }

    /**
     * Deux AUTRES processus PHP consomment 60 requêtes chacun, EN MÊME TEMPS, puis ce processus-ci
     * présente la 121ᵉ. Sans stockage partagé, chacun compterait pour soi et la 121ᵉ passerait ; sans
     * verrou, deux lectures concurrentes perdraient des incréments et elle passerait aussi.
     */
    public function testLaLimiteEstCommuneAPlusieursProcessus(): void
    {
        ['secret' => $secret] = $this->issueKey($this->createApplication()['id']);

        $script = __DIR__.'/../call-v1-me.php';
        $processes = [];
        foreach ([1, 2] as $n) {
            // ⚠ LE JOURNAL VA DANS UN FICHIER, PAS DANS UN TUYAU : le noyau de test écrit ses traces
            //   sur la sortie d'erreur, et un tuyau plein que personne ne lit bloque l'enfant à jamais.
            $log = (string) tempnam(sys_get_temp_dir(), 'v1-');
            $process = proc_open([\PHP_BINARY, '-d', 'memory_limit=1G', $script, $secret, '60'], [1 => ['pipe', 'w'], 2 => ['file', $log, 'w']], $pipes);
            self::assertIsResource($process);
            $processes[$n] = [$process, $pipes, $log];
        }

        foreach ($processes as $n => [$process, $pipes, $log]) {
            $out = stream_get_contents($pipes[1]);
            $code = proc_close($process);
            $tail = substr((string) file_get_contents($log), -2000);
            @unlink($log);
            self::assertSame(0, $code, sprintf('processus %d : %s', $n, $tail));
            self::assertSame(['200' => 60], json_decode((string) $out, true), sprintf('processus %d : %s', $n, $out));
        }

        $response = static::createClient()->request('GET', '/v1/me', ['headers' => ['Authorization' => 'Bearer '.$secret]]);
        self::assertSame(429, $response->getStatusCode(), 'la 121ᵉ requête, venue d’un troisième processus');
    }
}
