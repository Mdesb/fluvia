<?php

declare(strict_types=1);

namespace App\Ocr\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Ocr\Enum\OcrProvider;
use App\Ocr\State\OcrProviderConfigProcessor;
use App\Organisation\Entity\Etablissement;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Configuration du fournisseur d'extraction actif d'un établissement (RG-OCR-06, spec-ocr.md §4.5) —
 * 1 par établissement (contrainte unique). `manual` par défaut jusqu'à configuration explicite.
 *
 * ⚠ Clé API — garde de sécurité applicative (RG-OCR-06, CA-6) : `apiKeyEncrypted` n'est **jamais**
 * portée par un groupe de sérialisation (même patron que `ConfigCreancierSepa::creancierIbanChiffre`),
 * donc jamais exposée en API quel que soit le contexte demandé. Seul `hasApiKey()` (calculé) est
 * lisible. La clé en clair ne transite qu'en entrée du processor (`apiKeyPlain`, transitoire, jamais
 * mappée Doctrine) : chiffrée (réversible, `App\Ocr\Service\ChiffreurApiKeyOcr`, libsodium) avant
 * persistance — jamais stockée en clair.
 *
 * `establishment` est **volontairement absent** du groupe d'écriture : toujours dérivé côté serveur
 * (`ContexteEtablissement::etablissementActif()`) par `OcrProviderConfigProcessor`, jamais d'un id
 * transmis par le client (invariant noyau commun #1, échec fermé si aucun établissement actif).
 */
#[ORM\Entity]
#[ORM\Table(name: 'ocr_provider_config')]
#[ORM\UniqueConstraint(name: 'uniq_ocr_provider_config_establishment', columns: ['establishment_id'])]
#[ApiResource(
    shortName: 'OcrProviderConfig',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'ocr.configure')"),
        new Get(security: "is_granted('PERM', 'ocr.configure')"),
        new Post(security: "is_granted('PERM', 'ocr.configure')", processor: OcrProviderConfigProcessor::class),
        new Patch(security: "is_granted('PERM', 'ocr.configure')", processor: OcrProviderConfigProcessor::class),
    ],
    normalizationContext: ['groups' => ['ocr_config:read']],
    denormalizationContext: ['groups' => ['ocr_config:write']],
)]
// Pas de filtre `establishment` : redondant avec le cloisonnement (`PerimetreOcrExtension` restreint
// déjà la collection au périmètre de l'utilisateur) et il n'existe qu'UNE config par établissement
// (contrainte unique). Le SearchFilter `exact` sur une relation UUID était de surcroît inopérant
// (résolution IRI→BINARY(16) côté MariaDB). Le scoping par établissement est prouvé par le cloisonnement.
class OcrProviderConfig
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['ocr_config:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(name: 'establishment_id', nullable: false, unique: true)]
    #[Groups(['ocr_config:read'])]
    private ?Etablissement $establishment = null;

    #[ORM\Column(length: 16, enumType: OcrProvider::class, options: ['default' => 'manual'])]
    #[Groups(['ocr_config:read', 'ocr_config:write'])]
    private OcrProvider $provider = OcrProvider::Manual;

    /**
     * Coffre réversible (`ChiffreurApiKeyOcr`, libsodium `crypto_secretbox`) — nonce+cipher base64.
     * Volontairement **sans** `#[Groups]` : ne doit jamais apparaître dans une réponse API.
     */
    #[ORM\Column(name: 'api_key_encrypted', type: 'text', nullable: true)]
    private ?string $apiKeyEncrypted = null;

    /** Clé API en clair — champ transitoire (jamais mappé Doctrine), consommé par le processor. */
    #[Groups(['ocr_config:write'])]
    private string $apiKeyPlain = '';

    #[ORM\Column(name: 'confidence_threshold', type: 'decimal', precision: 3, scale: 2, options: ['default' => '0.70'])]
    #[Assert\Range(min: 0, max: 1)]
    #[Groups(['ocr_config:read', 'ocr_config:write'])]
    private string $confidenceThreshold = '0.70';

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    #[Groups(['ocr_config:read'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    #[Groups(['ocr_config:read'])]
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

    public function getEstablishment(): ?Etablissement
    {
        return $this->establishment;
    }

    public function setEstablishment(?Etablissement $establishment): self
    {
        $this->establishment = $establishment;

        return $this;
    }

    public function getProvider(): OcrProvider
    {
        return $this->provider;
    }

    public function setProvider(OcrProvider $provider): self
    {
        $this->provider = $provider;

        return $this;
    }

    public function getApiKeyEncrypted(): ?string
    {
        return $this->apiKeyEncrypted;
    }

    public function setApiKeyEncrypted(?string $apiKeyEncrypted): self
    {
        $this->apiKeyEncrypted = $apiKeyEncrypted;

        return $this;
    }

    public function getApiKeyPlain(): string
    {
        return $this->apiKeyPlain;
    }

    public function setApiKeyPlain(string $apiKeyPlain): self
    {
        $this->apiKeyPlain = $apiKeyPlain;

        return $this;
    }

    /**
     * Calculé — remplace `apiKeyEncrypted` en lecture API (CA-6 : jamais la clé, jamais même chiffrée).
     * `#[SerializedName]` explicite : le préfixe `has` de la méthode serait sinon dépouillé par le
     * normalizer Symfony (accessor prefixes `get`/`is`/`has`/`can`), qui exposerait la propriété
     * virtuelle sous le nom `apiKey` plutôt que `hasApiKey`.
     */
    #[Groups(['ocr_config:read'])]
    #[SerializedName('hasApiKey')]
    public function hasApiKey(): bool
    {
        return $this->apiKeyEncrypted !== null && $this->apiKeyEncrypted !== '';
    }

    public function getConfidenceThreshold(): string
    {
        return $this->confidenceThreshold;
    }

    public function setConfidenceThreshold(string $confidenceThreshold): self
    {
        $this->confidenceThreshold = $confidenceThreshold;

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
