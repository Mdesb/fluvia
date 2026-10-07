<?php

declare(strict_types=1);

namespace App\PublicApi\Webhook;

use App\Securite\Crypto\ChiffreurSecret;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Le coffre de l'URL et du secret de signature des abonnements partenaires (libsodium, clé dédiée
 * `PARTNER_WEBHOOK_KEY`) — même patron que `WebhookUrlCipher`, clé distincte : compromettre les
 * connecteurs d'un exploitant ne doit pas donner les secrets des partenaires.
 *
 * ⚠ `app/.env` en porte un MARQUEUR versionné, donc public : il est refusé hors environnement de test,
 * à la première utilisation — pas à la construction, que déclenchent aussi des écouteurs instanciés à
 * la fin de n'importe quelle commande (voir `SupportReferenceSigner::reference()`). La vraie clé est générée par installation (`infra/deploy-preprod.sh`) et
 * exigée par `compose.preprod.yaml`.
 */
final class PartnerWebhookCipher
{
    private const MARKER_PREFIX = 'A_GENERER';

    public function __construct(
        #[Autowire(env: 'PARTNER_WEBHOOK_KEY')] private readonly string $keyBase64,
        #[Autowire(param: 'kernel.environment')] private readonly string $environment,
    ) {
    }

    public function encrypt(string $plain): string
    {
        return $this->cipher()->chiffrer($plain);
    }

    /** `null` si illisible (clé changée) : l'appelant le consigne au lieu de planter. */
    public function decrypt(string $encrypted): ?string
    {
        try {
            return '' === $encrypted ? null : $this->cipher()->dechiffrer($encrypted);
        } catch (\LogicException $e) {
            throw $e;
        } catch (\Throwable) {
            return null;
        }
    }

    private function cipher(): ChiffreurSecret
    {
        if ('test' !== $this->environment && ('' === $this->keyBase64 || str_starts_with($this->keyBase64, self::MARKER_PREFIX))) {
            throw new \LogicException('PARTNER_WEBHOOK_KEY n’est pas définie : app/.env n’en porte qu’un marqueur public. Générez une clé par installation (infra/deploy-preprod.sh).');
        }

        return new ChiffreurSecret($this->keyBase64);
    }
}
