<?php

declare(strict_types=1);

namespace App\Tests\Acces\Support;

use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Acces\Hardware\Simulated\TerminalTransport;

/**
 * Transport du terminal simulé à travers le VRAI noyau HTTP (#337) : authentification par jeton de
 * terminal, contrôle de portée, sérialisation — tout ce qu'une borne réelle traverserait.
 */
final class KernelTerminalTransport implements TerminalTransport
{
    /** @var list<array{path: string, status: int}> */
    public array $calls = [];

    public function __construct(
        private readonly Client $client,
        private readonly string $secret,
    ) {
    }

    public function snapshot(int $since): array
    {
        $entries = [];
        $page = 1;
        $until = null;
        do {
            $query = ['depuis' => $since, 'page' => $page] + ($until !== null ? ['jusqua' => $until] : []);
            $body = $this->call('GET', '/api/terminal/snapshot', ['query' => $query]);
            $until ??= $body['versionCourante'];
            array_push($entries, ...$body['entrees']);
            ++$page;
        } while ($body['pageSuivante'] === true);

        return ['versionCourante' => (int) $until, 'entrees' => $entries];
    }

    public function validate(array $event): array
    {
        return $this->call('POST', '/api/terminal/passages', ['json' => $event]);
    }

    public function pushBatch(array $events): array
    {
        return $this->call('POST', '/api/terminal/passages/lot', ['json' => ['lot' => $events]]);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function call(string $method, string $path, array $options): array
    {
        $response = $this->client->request($method, $path, $options + ['headers' => ['Authorization' => 'Bearer ' . $this->secret]]);
        $this->calls[] = ['path' => $path, 'status' => $response->getStatusCode()];
        if ($response->getStatusCode() >= 300) {
            throw new \RuntimeException(sprintf('%s %s → %d : %s', $method, $path, $response->getStatusCode(), $response->getContent(false)));
        }

        return $response->toArray();
    }
}
