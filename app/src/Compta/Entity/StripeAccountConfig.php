<?php

declare(strict_types=1);

namespace App\Compta\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Boutique\State\StripeAccountConfigProcessor;
use App\Compta\Enum\StripeKeyMode;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Compte Stripe d'un exploitant (lot D3-B de #365) : clé secrète et secret de webhook, **chiffrés**
 * (`App\Compta\Service\StripeKeyEncryptor`, libsodium), 1 par `ProfilExploitant` (contrainte unique).
 *
 * Invariant : une ligne a toujours une clé ; le secret de webhook est facultatif. Retirer = supprimer
 * la ligne.
 *
 * ⚠ Les colonnes chiffrées n'ont **aucun** groupe de sérialisation, aujourd'hui comme demain : ni la
 * valeur en clair ni sa version chiffrée ne sortent par l'API (G-6). Le mode (`test`/`live`) et les
 * dates de saisie disent *quelle* valeur a changé sans jamais la montrer.
 */
#[ORM\Entity]
#[ORM\Table(name: 'accounting_stripe_account_config')]
#[ORM\UniqueConstraint(name: 'uniq_stripe_account_config_profile', columns: ['business_profile_id'])]
#[ApiResource(
    shortName: 'StripeAccountConfig',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'payment.configure')"),
        new Get(security: "is_granted('PERM', 'payment.configure')"),
        // `stripe_config:create` : le profil n'est désignable qu'à la création (jamais re-rattacher une clé).
        new Post(
            security: "is_granted('PERM', 'payment.configure')",
            processor: StripeAccountConfigProcessor::class,
            denormalizationContext: ['groups' => ['stripe_config:write', 'stripe_config:create']],
        ),
        new Patch(security: "is_granted('PERM', 'payment.configure')", processor: StripeAccountConfigProcessor::class),
        new Delete(security: "is_granted('PERM', 'payment.configure')"),
    ],
    normalizationContext: ['groups' => ['stripe_config:read']],
    denormalizationContext: ['groups' => ['stripe_config:write']],
)]
// Pas de filtre : la collection est déjà bornée à l'établissement actif (`AccountingScopeExtension`,
// `VIA_PROFIL`) et l'écran retient la ligne de son profil. Cloisonnement par le profil exploitant, pas
// par un champ `etablissement` redondant.
class StripeAccountConfig
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['stripe_config:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: ProfilExploitant::class)]
    #[ORM\JoinColumn(name: 'business_profile_id', nullable: false)]
    #[Assert\NotNull]
    #[Groups(['stripe_config:read', 'stripe_config:create'])]
    private ?ProfilExploitant $businessProfile = null;

    /** Clé secrète chiffrée (nonce + chiffré, base64). Jamais exposée. */
    #[ORM\Column(name: 'secret_key_encrypted', type: 'text')]
    private string $secretKeyEncrypted = '';

    /** Secret de signature du webhook, chiffré. Jamais exposé. */
    #[ORM\Column(name: 'webhook_secret_encrypted', type: 'text', nullable: true)]
    private ?string $webhookSecretEncrypted = null;

    /**
     * Valeurs en clair, **transitoires** : jamais mappées Doctrine, jamais lues (aucun groupe de lecture),
     * consommées puis vidées par `StripeAccountConfigProcessor`. `null` et `''` sont valides (un PATCH
     * sans cette valeur la laisse intacte). Aucun message de validation ne cite la valeur saisie.
     */
    #[Assert\Regex(pattern: '/^(sk|rk)_(test|live)_[A-Za-z0-9]{8,247}\z/', message: 'La clé secrète Stripe doit commencer par sk_ ou rk_ (clé restreinte), suivi de test_ ou live_, puis 8 à 247 lettres ou chiffres.')]
    #[Groups(['stripe_config:write'])]
    private ?string $secretKeyPlain = null;

    #[Assert\Regex(pattern: '/^whsec_[A-Za-z0-9+\/=]{8,200}\z/', message: 'Le secret de signature du webhook doit commencer par whsec_, suivi de 8 à 200 caractères (lettres, chiffres, + / =).')]
    #[Groups(['stripe_config:write'])]
    private ?string $webhookSecretPlain = null;

    #[ORM\Column(name: 'key_mode', length: 8, enumType: StripeKeyMode::class)]
    #[Groups(['stripe_config:read'])]
    private StripeKeyMode $keyMode = StripeKeyMode::Test;

    #[ORM\Column(name: 'key_updated_at', type: 'datetime_immutable', nullable: true)]
    #[Groups(['stripe_config:read'])]
    private ?\DateTimeImmutable $keyUpdatedAt = null;

    #[ORM\Column(name: 'webhook_secret_updated_at', type: 'datetime_immutable', nullable: true)]
    #[Groups(['stripe_config:read'])]
    private ?\DateTimeImmutable $webhookSecretUpdatedAt = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    #[Groups(['stripe_config:read'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    #[Groups(['stripe_config:read'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getBusinessProfile(): ?ProfilExploitant
    {
        return $this->businessProfile;
    }

    public function setBusinessProfile(?ProfilExploitant $businessProfile): self
    {
        $this->businessProfile = $businessProfile;

        return $this;
    }

    /**
     * Calculés : remplacent les colonnes chiffrées en lecture (G-6 : jamais la clé, jamais même chiffrée).
     * `#[SerializedName]` explicite : sans lui le normalizer Symfony dépouille le préfixe `has`.
     */
    #[Groups(['stripe_config:read'])]
    #[SerializedName('hasSecretKey')]
    public function hasSecretKey(): bool
    {
        return $this->secretKeyEncrypted !== '';
    }

    #[Groups(['stripe_config:read'])]
    #[SerializedName('hasWebhookSecret')]
    public function hasWebhookSecret(): bool
    {
        return $this->webhookSecretEncrypted !== null && $this->webhookSecretEncrypted !== '';
    }

    public function getSecretKeyEncrypted(): string
    {
        return $this->secretKeyEncrypted;
    }

    public function setSecretKeyEncrypted(string $secretKeyEncrypted): self
    {
        $this->secretKeyEncrypted = $secretKeyEncrypted;

        return $this;
    }

    public function getWebhookSecretEncrypted(): ?string
    {
        return $this->webhookSecretEncrypted;
    }

    public function setWebhookSecretEncrypted(?string $webhookSecretEncrypted): self
    {
        $this->webhookSecretEncrypted = $webhookSecretEncrypted;

        return $this;
    }

    public function getSecretKeyPlain(): ?string
    {
        return $this->secretKeyPlain;
    }

    public function setSecretKeyPlain(#[\SensitiveParameter] ?string $secretKeyPlain): self
    {
        $this->secretKeyPlain = $secretKeyPlain;

        return $this;
    }

    public function getWebhookSecretPlain(): ?string
    {
        return $this->webhookSecretPlain;
    }

    public function setWebhookSecretPlain(#[\SensitiveParameter] ?string $webhookSecretPlain): self
    {
        $this->webhookSecretPlain = $webhookSecretPlain;

        return $this;
    }

    /**
     * Vide les valeurs en clair une fois chiffrées. Le processeur ne « pose » rien dans ces champs : il ne fait que les
     * effacer, la contrainte de format ayant porté sur la valeur du CLIENT (garde-fou n°34 : pas une collision).
     */
    public function eraseTransientSecrets(): self
    {
        $this->secretKeyPlain = null;
        $this->webhookSecretPlain = null;

        return $this;
    }

    public function getKeyMode(): StripeKeyMode
    {
        return $this->keyMode;
    }

    public function setKeyMode(StripeKeyMode $keyMode): self
    {
        $this->keyMode = $keyMode;

        return $this;
    }

    public function getKeyUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->keyUpdatedAt;
    }

    public function setKeyUpdatedAt(?\DateTimeImmutable $keyUpdatedAt): self
    {
        $this->keyUpdatedAt = $keyUpdatedAt;

        return $this;
    }

    public function getWebhookSecretUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->webhookSecretUpdatedAt;
    }

    public function setWebhookSecretUpdatedAt(?\DateTimeImmutable $webhookSecretUpdatedAt): self
    {
        $this->webhookSecretUpdatedAt = $webhookSecretUpdatedAt;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touchUpdatedAt(): self
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }
}
