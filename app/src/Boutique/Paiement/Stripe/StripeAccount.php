<?php

declare(strict_types=1);

namespace App\Boutique\Paiement\Stripe;

/**
 * Le compte Stripe avec lequel UN appel est fait (D3-B de #365) : la clé secrète, la base d'API, et d'où il vient. Objet valeur, sans état partagé : l'adaptateur le
 * reçoit à chaque appel et n'en garde aucun.
 *
 * ⚠ IL NE DOIT FUITER PAR AUCUN CANAL : propriétés privées (donc `json_encode` ne rend rien), `__debugInfo()`
 * expurgé (`var_dump`, `print_r`), `__serialize()` refusé (cache, session, file de messages), pas de
 * `__toString`, et `#[\SensitiveParameter]` sur le constructeur (la trace d'une exception ne cite pas les
 * valeurs). `var_export` n'est pas couvert par PHP : il n'est jamais appelé sur cet objet.
 */
final class StripeAccount
{
    public function __construct(
        #[\SensitiveParameter] private readonly string $secretKey,
        private readonly string $apiBase,
        public readonly StripeAccountSource $source,
        public readonly ?string $profileId = null,
    ) {
    }

    public function secretKey(): string
    {
        return $this->secretKey;
    }

    public function apiBase(): string
    {
        return $this->apiBase;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['source' => $this->source, 'profileId' => $this->profileId, 'secretKey' => '***'];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new \LogicException('Un compte Stripe ne se sérialise pas.');
    }

    public function __unserialize(array $data): void
    {
        throw new \LogicException('Un compte Stripe ne se désérialise pas.');
    }
}
