<?php

declare(strict_types=1);

namespace App\PublicApi\Webhook;

/** Résolution DNS d'un hôte de webhook — voir `DnsHostResolver` et `WebhookDestinationGuard`. */
interface HostResolver
{
    /** @return list<string> les adresses IPv4 et IPv6 de l'hôte, vide s'il ne résout pas */
    public function resolve(string $host): array;
}
