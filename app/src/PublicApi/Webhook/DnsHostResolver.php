<?php

declare(strict_types=1);

namespace App\PublicApi\Webhook;

use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Résout un nom d'hôte en adresses (A et AAAA). Injectable : les tests posent un faux résolveur pour
 * prouver qu'un NOM qui résout vers une adresse interne est refusé.
 *
 * ⚠ `dns_get_record()` n'a pas de délai propre : il suit le résolveur du système. Les conteneurs qui
 * livrent portent `RES_OPTIONS=timeout:2 attempts:1` (compose.preprod.yaml) — le délai DNS de 2 s de
 * la spec vit là.
 */
#[AsAlias(HostResolver::class)]
final class DnsHostResolver implements HostResolver
{
    public function resolve(string $host): array
    {
        $records = @dns_get_record($host, \DNS_A | \DNS_AAAA);
        if (!\is_array($records)) {
            return [];
        }

        $ips = [];
        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (\is_string($ip)) {
                $ips[] = $ip;
            }
        }

        return $ips;
    }
}
