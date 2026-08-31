<?php

declare(strict_types=1);

namespace App\Social\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Social\Enum\SocialPublicationStatus;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Une ligne, un réseau (D14) — la destinée d'un message sur un compte donné.
 *
 * C'est l'entité qui porte la vérité : l'état, l'identifiant rendu par le réseau, l'erreur exacte, le
 * nombre de tentatives. Et c'est elle qui portera les statistiques en SOC-3, donc la jointure entre ce
 * qu'on a publié et ce que ça a produit — c'est là qu'est la valeur du module, pas dans la publication
 * elle-même : aucun outil du marché ne peut dire si un message a rempli le cours d'aquagym du samedi,
 * faute d'avoir les réservations dans la même base.
 *
 * **Aucune opération d'écriture en API.** Une publication naît d'un `SocialPost` et n'évolue ensuite
 * que par le travail sortant (SOC-2). Laisser un client écrire « publié » sur une ligne que personne
 * n'a envoyée ferait mentir l'historique à l'endroit précis où on ira chercher la vérité.
 *
 * Le cloisonnement passe par le message (`SocialScopeExtension`, chaîne `post`) : la publication ne
 * porte pas son établissement en propre pour qu'il ne puisse jamais diverger de celui du message.
 */
#[ORM\Entity]
#[ORM\Table(name: 'social_publication')]
#[ORM\UniqueConstraint(name: 'uniq_social_publication_post_account', columns: ['post_id', 'account_id'])]
#[ApiResource(
    shortName: 'SocialPublication',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'social.read_post')"),
        new Get(security: "is_granted('PERM', 'social.read_post')"),
    ],
    normalizationContext: ['groups' => ['social_publication:read']],
)]
class SocialPublication
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['social_publication:read', 'social_post:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: SocialPost::class, inversedBy: 'publications')]
    #[ORM\JoinColumn(name: 'post_id', nullable: false)]
    #[Groups(['social_publication:read'])]
    private ?SocialPost $post = null;

    #[ORM\ManyToOne(targetEntity: SocialAccount::class)]
    #[ORM\JoinColumn(name: 'account_id', nullable: false)]
    #[Groups(['social_publication:read', 'social_post:read'])]
    private ?SocialAccount $account = null;

    #[ORM\Column(length: 32, enumType: SocialPublicationStatus::class, options: ['default' => 'pending'])]
    #[Groups(['social_publication:read', 'social_post:read'])]
    private SocialPublicationStatus $status = SocialPublicationStatus::Pending;

    /** Identifiant rendu par le réseau — la seule preuve que quelque chose est bien paru. */
    #[ORM\Column(name: 'remote_post_id', length: 191, nullable: true)]
    #[Groups(['social_publication:read', 'social_post:read'])]
    private ?string $remotePostId = null;

    #[ORM\Column(name: 'remote_url', length: 512, nullable: true)]
    #[Groups(['social_publication:read', 'social_post:read'])]
    private ?string $remoteUrl = null;

    /**
     * Motif rendu par le réseau, tel quel. On ne le traduit pas en vocabulaire maison : un code
     * réécrit fait diverger le diagnostic de ce que la documentation du réseau permet de chercher.
     */
    #[ORM\Column(name: 'error_code', length: 64, nullable: true)]
    #[Groups(['social_publication:read', 'social_post:read'])]
    private ?string $errorCode = null;

    #[ORM\Column(name: 'error_message', type: 'text', nullable: true)]
    #[Groups(['social_publication:read', 'social_post:read'])]
    private ?string $errorMessage = null;

    #[ORM\Column(name: 'attempts', type: 'integer', options: ['default' => 0])]
    #[Groups(['social_publication:read', 'social_post:read'])]
    private int $attempts = 0;

    #[ORM\Column(name: 'published_at', type: 'datetime_immutable', nullable: true)]
    #[Groups(['social_publication:read', 'social_post:read'])]
    private ?\DateTimeImmutable $publishedAt = null;

    /**
     * Instant où la publication a été confiée à la file.
     *
     * Sert de garde contre la double mise en file : l'ordonnanceur ne reprend que ce qui n'a jamais
     * été confié. Sans elle, deux passages rapprochés de l'ordonnanceur, ou un worker en retard,
     * feraient publier deux fois le même message — et un doublon paru en public ne se rattrape pas.
     */
    #[ORM\Column(name: 'queued_at', type: 'datetime_immutable', nullable: true)]
    #[Groups(['social_publication:read', 'social_post:read'])]
    private ?\DateTimeImmutable $queuedAt = null;

    /**
     * Instant où l'on a cessé de collecter les statistiques, et pourquoi.
     *
     * Un statut supprimé chez le réseau ne réapparaîtra pas : sans cette borne, la collecte planifiée
     * le redemanderait à chaque passage, pour toujours, en consommant le quota de l'établissement.
     * On garde la publication et son historique — on arrête seulement d'interroger.
     */
    #[ORM\Column(name: 'metrics_stopped_at', type: 'datetime_immutable', nullable: true)]
    #[Groups(['social_publication:read', 'social_post:read'])]
    private ?\DateTimeImmutable $metricsStoppedAt = null;

    #[ORM\Column(name: 'metrics_stopped_reason', length: 64, nullable: true)]
    #[Groups(['social_publication:read', 'social_post:read'])]
    private ?string $metricsStoppedReason = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    #[Groups(['social_publication:read'])]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getPost(): ?SocialPost
    {
        return $this->post;
    }

    public function setPost(?SocialPost $post): self
    {
        $this->post = $post;

        return $this;
    }

    public function getAccount(): ?SocialAccount
    {
        return $this->account;
    }

    public function setAccount(?SocialAccount $account): self
    {
        $this->account = $account;

        return $this;
    }

    public function getStatus(): SocialPublicationStatus
    {
        return $this->status;
    }

    public function setStatus(SocialPublicationStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getRemotePostId(): ?string
    {
        return $this->remotePostId;
    }

    public function setRemotePostId(?string $remotePostId): self
    {
        $this->remotePostId = $remotePostId;

        return $this;
    }

    public function getRemoteUrl(): ?string
    {
        return $this->remoteUrl;
    }

    public function setRemoteUrl(?string $remoteUrl): self
    {
        $this->remoteUrl = $remoteUrl;

        return $this;
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    public function setErrorCode(?string $errorCode): self
    {
        $this->errorCode = $errorCode;

        return $this;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): self
    {
        $this->errorMessage = $errorMessage;

        return $this;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function setAttempts(int $attempts): self
    {
        $this->attempts = $attempts;

        return $this;
    }

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function setPublishedAt(?\DateTimeImmutable $publishedAt): self
    {
        $this->publishedAt = $publishedAt;

        return $this;
    }

    public function getQueuedAt(): ?\DateTimeImmutable
    {
        return $this->queuedAt;
    }

    public function setQueuedAt(?\DateTimeImmutable $queuedAt): self
    {
        $this->queuedAt = $queuedAt;

        return $this;
    }

    public function getMetricsStoppedAt(): ?\DateTimeImmutable
    {
        return $this->metricsStoppedAt;
    }

    public function getMetricsStoppedReason(): ?string
    {
        return $this->metricsStoppedReason;
    }

    public function stopMetrics(string $reason): self
    {
        $this->metricsStoppedAt = new \DateTimeImmutable();
        $this->metricsStoppedReason = $reason;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
