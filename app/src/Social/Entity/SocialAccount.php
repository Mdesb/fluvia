<?php

declare(strict_types=1);

namespace App\Social\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Organisation\Entity\Etablissement;
use App\Social\Enum\SocialAccountStatus;
use App\Social\Enum\SocialNetwork;
use App\Social\State\SocialAccountProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Compte social connecté d'un établissement (D14, SOC-1) — le coffre à jetons.
 *
 * Un jeton ne sort jamais. `accessTokenEncrypted` et `refreshTokenEncrypted` ne portent aucun groupe
 * de sérialisation : ils sont donc invisibles en API quel que soit le contexte demandé — même patron
 * que `OcrProviderConfig::apiKeyEncrypted` et `ConfigCreancierSepa::creancierIbanChiffre`. Seul
 * `hasAccessToken()` (calculé) est lisible. Le jeton en clair ne transite qu'en entrée du processor
 * (`accessTokenPlain`, transitoire, jamais mappé Doctrine), chiffré avant persistance par
 * `App\Social\Crypto\SocialTokenCipher`.
 *
 * `establishment` est volontairement absent du groupe d'écriture : toujours dérivé côté serveur
 * (`ContexteEtablissement::etablissementActif()`) par `SocialAccountProcessor`, jamais d'un id
 * transmis par le client (invariant noyau commun #1, D3/D8). C'est la forme exacte des seize IDOR
 * trouvés dans ce dépôt — une entité résolue depuis le corps de la requête et jamais confrontée au
 * périmètre. Échec fermé en 404 et non en 403 : un 403 distinguerait « existe, pas à toi » de
 * « n'existe pas », donc énumérerait les comptes des autres établissements.
 *
 * Unicité par (établissement, réseau, compte distant) : reconnecter le même compte Mastodon met à
 * jour le jeton existant plutôt que d'empiler des lignes dont on ne saurait plus laquelle publie.
 */
#[ORM\Entity]
#[ORM\Table(name: 'social_account')]
#[ORM\UniqueConstraint(
    name: 'uniq_social_account_establishment_network_remote',
    columns: ['establishment_id', 'network', 'remote_account_id'],
)]
#[ApiResource(
    shortName: 'SocialAccount',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'social.read_account')"),
        new Get(security: "is_granted('PERM', 'social.read_account')"),
        new Post(security: "is_granted('PERM', 'social.manage_account')", processor: SocialAccountProcessor::class),
        new Patch(security: "is_granted('PERM', 'social.manage_account')", processor: SocialAccountProcessor::class),
    ],
    normalizationContext: ['groups' => ['social_account:read']],
    denormalizationContext: ['groups' => ['social_account:write']],
)]
// Pas de filtre `establishment` : redondant avec le cloisonnement (`SocialScopeExtension` restreint
// déjà la collection au périmètre serveur de l'utilisateur), et un filtre exposé serait une invitation
// à croire qu'un id client peut porter le périmètre.
class SocialAccount
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['social_account:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(name: 'establishment_id', nullable: false)]
    #[Groups(['social_account:read'])]
    private ?Etablissement $establishment = null;

    #[ORM\Column(length: 32, enumType: SocialNetwork::class)]
    #[Groups(['social_account:read', 'social_account:write'])]
    private SocialNetwork $network = SocialNetwork::Mastodon;

    /**
     * Hôte du réseau — instance Mastodon (fédérée, le jeton ne vaut que pour elle) ou fournisseur de
     * données personnel Bluesky. Renseigné par le processor depuis `SocialNetwork::defaultHost()`
     * quand le client ne le fournit pas et que le réseau en admet un.
     */
    #[ORM\Column(name: 'host', length: 255, nullable: true)]
    #[Assert\Url(protocols: ['https'], message: 'social.error.host_must_be_https')]
    #[Groups(['social_account:read', 'social_account:write'])]
    private ?string $host = null;

    /** Identifiant du compte chez le réseau — c'est lui qui fait l'unicité, pas le pseudonyme. */
    #[ORM\Column(name: 'remote_account_id', length: 191)]
    #[Assert\NotBlank(message: 'social.error.remote_account_id_required')]
    #[Groups(['social_account:read', 'social_account:write'])]
    private string $remoteAccountId = '';

    /** Pseudonyme affiché (`@compte@instance`) — confort d'affichage, jamais une clé. */
    #[ORM\Column(name: 'handle', length: 191)]
    #[Assert\NotBlank(message: 'social.error.handle_required')]
    #[Groups(['social_account:read', 'social_account:write'])]
    private string $handle = '';

    /**
     * Coffre réversible (`SocialTokenCipher`, libsodium `crypto_secretbox`) — nonce+chiffré en base64.
     * Volontairement sans `#[Groups]` : ne doit jamais apparaître dans une réponse d'API.
     */
    #[ORM\Column(name: 'access_token_encrypted', type: 'text', nullable: true)]
    private ?string $accessTokenEncrypted = null;

    /** Idem — sans `#[Groups]`, jamais exposé. */
    #[ORM\Column(name: 'refresh_token_encrypted', type: 'text', nullable: true)]
    private ?string $refreshTokenEncrypted = null;

    /** Jeton d'accès en clair — champ transitoire (jamais mappé Doctrine), consommé par le processor. */
    #[Groups(['social_account:write'])]
    private string $accessTokenPlain = '';

    /** Jeton de rafraîchissement en clair — champ transitoire, consommé par le processor. */
    #[Groups(['social_account:write'])]
    private string $refreshTokenPlain = '';

    /**
     * Expiration annoncée par le réseau. Indicative : un jeton peut être révoqué avant, et certains
     * réseaux n'annoncent rien. C'est le refus du réseau qui fait foi, pas cette date — d'où
     * `SocialAccountStatus::TokenExpired`, positionné sur réponse du réseau et non par une horloge
     * (D20 : aucune assertion d'horloge dans la suite fonctionnelle).
     */
    #[ORM\Column(name: 'token_expires_at', type: 'datetime_immutable', nullable: true)]
    #[Groups(['social_account:read', 'social_account:write'])]
    private ?\DateTimeImmutable $tokenExpiresAt = null;

    #[ORM\Column(length: 32, enumType: SocialAccountStatus::class, options: ['default' => 'connected'])]
    #[Groups(['social_account:read', 'social_account:write'])]
    private SocialAccountStatus $status = SocialAccountStatus::Connected;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    #[Groups(['social_account:read'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    #[Groups(['social_account:read'])]
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

    public function getNetwork(): SocialNetwork
    {
        return $this->network;
    }

    public function setNetwork(SocialNetwork $network): self
    {
        $this->network = $network;

        return $this;
    }

    public function getHost(): ?string
    {
        return $this->host;
    }

    public function setHost(?string $host): self
    {
        $this->host = $host;

        return $this;
    }

    public function getRemoteAccountId(): string
    {
        return $this->remoteAccountId;
    }

    public function setRemoteAccountId(string $remoteAccountId): self
    {
        $this->remoteAccountId = $remoteAccountId;

        return $this;
    }

    public function getHandle(): string
    {
        return $this->handle;
    }

    public function setHandle(string $handle): self
    {
        $this->handle = $handle;

        return $this;
    }

    public function getAccessTokenEncrypted(): ?string
    {
        return $this->accessTokenEncrypted;
    }

    public function setAccessTokenEncrypted(?string $accessTokenEncrypted): self
    {
        $this->accessTokenEncrypted = $accessTokenEncrypted;

        return $this;
    }

    public function getRefreshTokenEncrypted(): ?string
    {
        return $this->refreshTokenEncrypted;
    }

    public function setRefreshTokenEncrypted(?string $refreshTokenEncrypted): self
    {
        $this->refreshTokenEncrypted = $refreshTokenEncrypted;

        return $this;
    }

    public function getAccessTokenPlain(): string
    {
        return $this->accessTokenPlain;
    }

    public function setAccessTokenPlain(string $accessTokenPlain): self
    {
        $this->accessTokenPlain = $accessTokenPlain;

        return $this;
    }

    public function getRefreshTokenPlain(): string
    {
        return $this->refreshTokenPlain;
    }

    public function setRefreshTokenPlain(string $refreshTokenPlain): self
    {
        $this->refreshTokenPlain = $refreshTokenPlain;

        return $this;
    }

    /**
     * Seule fenêtre lisible sur le coffre : « y a-t-il un jeton », jamais lequel. C'est ce qu'une
     * interface a besoin de savoir pour afficher « reconnecter ».
     */
    #[Groups(['social_account:read'])]
    public function hasAccessToken(): bool
    {
        return $this->accessTokenEncrypted !== null && $this->accessTokenEncrypted !== '';
    }

    #[Groups(['social_account:read'])]
    public function hasRefreshToken(): bool
    {
        return $this->refreshTokenEncrypted !== null && $this->refreshTokenEncrypted !== '';
    }

    public function getTokenExpiresAt(): ?\DateTimeImmutable
    {
        return $this->tokenExpiresAt;
    }

    public function setTokenExpiresAt(?\DateTimeImmutable $tokenExpiresAt): self
    {
        $this->tokenExpiresAt = $tokenExpiresAt;

        return $this;
    }

    public function getStatus(): SocialAccountStatus
    {
        return $this->status;
    }

    public function setStatus(SocialAccountStatus $status): self
    {
        $this->status = $status;

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
