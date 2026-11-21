<?php

declare(strict_types=1);

namespace App\Boutique\Paiement\Stripe;

use App\Compta\Entity\ProfilExploitant;
use App\Compta\Entity\StripeAccountConfig;
use App\Compta\Enum\TypeExploitant;
use App\Compta\Service\StripeKeyEncryptor;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Le compte Stripe d'un exploitant (D3-B de #365, G-2/G-3) : sa propre clé si elle est saisie, sinon la clé
 * globale de l'environnement (repli), sinon aucun — la carte n'est alors pas proposée.
 *
 * **Aucun repli silencieux.** Si la ligne de l'exploitant existe mais ne se déchiffre pas (clé maîtresse
 * changée ou absente), on rend un 503 et on journalise l'événement SANS valeur : retomber sur la clé globale
 * enverrait l'argent d'un exploitant sur un autre compte.
 *
 * `webhookSecretFor()` est le SEUL accès de la route de notification avant la vérification de signature :
 * une seule requête, sur la table de configuration, par identifiant ; ni le profil ni la commande.
 */
final class StripeAccountResolver
{
    private const DEFAULT_API_BASE = 'https://api.stripe.com';
    private const KEY_PATTERN = '/^(sk|rk)_(test|live)_[A-Za-z0-9]+$/';
    private const UUID_PATTERN = '/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i';

    private readonly string $globalKey;
    private readonly string $globalWebhookSecret;
    private readonly string $apiBase;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly StripeKeyEncryptor $encryptor,
        private readonly LoggerInterface $logger,
        #[Autowire(env: 'default::STRIPE_SECRET_KEY')] #[\SensitiveParameter] ?string $secretKey = null,
        #[Autowire(env: 'default::STRIPE_API_BASE')] ?string $apiBase = null,
        #[Autowire(env: 'default::STRIPE_WEBHOOK_SECRET')] #[\SensitiveParameter] ?string $webhookSecret = null,
    ) {
        $this->globalKey = trim((string) $secretKey);
        $this->globalWebhookSecret = trim((string) $webhookSecret);
        $this->apiBase = rtrim(trim((string) $apiBase) ?: self::DEFAULT_API_BASE, '/');
    }

    /**
     * @throws ServiceUnavailableHttpException 503 si la clé saisie de l'exploitant est illisible (jamais de repli global)
     */
    public function forProfile(ProfilExploitant $profile): ?StripeAccount
    {
        // Une régie directe encaisse par PayFiP : aucune clé Stripe, même si une ligne subsiste.
        if ($profile->getType() === TypeExploitant::RegieDirecte) {
            return null;
        }

        $config = $this->em->getRepository(StripeAccountConfig::class)->findOneBy(['businessProfile' => $profile->getId()]);
        if ($config instanceof StripeAccountConfig) {
            return $this->profileAccount($config, $profile);
        }

        if (preg_match(self::KEY_PATTERN, $this->globalKey) === 1) {
            return new StripeAccount($this->globalKey, $this->apiBase, StripeAccountSource::Global);
        }

        return null;
    }

    /**
     * Le secret de signature à utiliser pour une notification : `null` = adresse globale. Toute anomalie
     * (identifiant mal formé, inconnu, exploitant sans secret, secret illisible) rend `''` : la signature
     * échouera, comme pour n'importe quelle autre cause — aucun oracle.
     */
    public function webhookSecretFor(?string $profileId): string
    {
        if ($profileId === null) {
            return $this->globalWebhookSecret;
        }
        if (preg_match(self::UUID_PATTERN, $profileId) !== 1) {
            return '';
        }

        $encrypted = $this->em->getConnection()->fetchOne(
            'SELECT webhook_secret_encrypted FROM accounting_stripe_account_config WHERE business_profile_id = ?',
            [Uuid::fromString($profileId)->toBinary()],
        );
        if (!\is_string($encrypted) || $encrypted === '') {
            return '';
        }

        try {
            return $this->encryptor->decrypt($encrypted);
        } catch (\RuntimeException) {
            $this->logger->warning('Secret de notification Stripe illisible', ['profil' => $profileId, 'motif' => 'dechiffrement_impossible']);

            return '';
        }
    }

    private function profileAccount(StripeAccountConfig $config, ProfilExploitant $profile): StripeAccount
    {
        try {
            $key = $this->encryptor->decrypt($config->getSecretKeyEncrypted());
        } catch (\RuntimeException) {
            // Jamais la valeur, ni le chiffré : l'identifiant du profil et le motif suffisent à l'opérateur.
            $this->logger->error('Clé Stripe de l\'exploitant illisible : paiement par carte indisponible', ['profil' => (string) $profile->getId(), 'motif' => 'dechiffrement_impossible']);

            throw new ServiceUnavailableHttpException(null, 'Le paiement par carte est momentanément indisponible.');
        }

        return new StripeAccount($key, $this->apiBase, StripeAccountSource::Profile, (string) $profile->getId());
    }
}
