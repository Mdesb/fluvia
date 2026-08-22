<?php

declare(strict_types=1);

namespace App\Dms\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use App\Dms\Processor\IssuePublicLinkProcessor;
use App\Dms\Processor\RevokePublicLinkProcessor;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * URL publique signée, expirante et révocable (RG-DMS-05 à 10) — la seule brèche autorisée au
 * cloisonnement, jamais le mode par défaut. `tokenHash` (sha256 du jeton) n'est **jamais** exposé par
 * le groupe `document_public_link:read` — le jeton en clair n'est porté qu'une seule fois par le DTO de
 * sortie `App\Dms\Dto\PublicLinkIssued`, à l'émission.
 */
#[ORM\Entity]
#[ORM\Table(name: 'dms_document_public_link')]
#[ORM\Index(columns: ['document_id'], name: 'IDX_DMS_PUBLIC_LINK_DOCUMENT')]
#[ApiResource(
    shortName: 'DocumentPublicLink',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'dms.manage_public_link')"),
        new Get(security: "is_granted('PERM', 'dms.manage_public_link')"),
        // Émission — sortie DTO `PublicLinkIssued` (jeton en clair une seule fois), jamais l'entité.
        // `documentId` désigne le document parent, pas une propriété de `DocumentPublicLink` : sans
        // cette déclaration API Platform ne sait pas résoudre la variable (même patron que
        // `App\Support\Entity\MessageTicket`). `read: false` : un POST crée, il ne relit pas une
        // ressource existante — `DmsScopeGuard::verify()` est appelé explicitement dans
        // le processor à partir de l'id brut (RG-DMS-02).
        new Post(
            uriTemplate: '/documents/{documentId}/public-links',
            uriVariables: ['documentId' => new Link(fromClass: Document::class, identifiers: ['id'])],
            read: false,
            input: false,
            security: "is_granted('PERM', 'dms.manage_public_link')",
            processor: IssuePublicLinkProcessor::class,
            output: \App\Dms\Dto\PublicLinkIssued::class,
            normalizationContext: ['groups' => ['public_link_issued:read']],
        ),
        new Post(
            uriTemplate: '/public-links/{id}/revoke',
            read: true,
            input: false,
            security: "is_granted('PERM', 'dms.manage_public_link')",
            processor: RevokePublicLinkProcessor::class,
            normalizationContext: ['groups' => ['document_public_link:read']],
        ),
    ],
    normalizationContext: ['groups' => ['document_public_link:read']],
)]
#[ApiFilter(SearchFilter::class, properties: ['document' => 'exact'])]
class DocumentPublicLink
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['document_public_link:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Document::class)]
    #[ORM\JoinColumn(name: 'document_id', nullable: false)]
    #[Groups(['document_public_link:read'])]
    private ?Document $document = null;

    #[ORM\ManyToOne(targetEntity: DocumentVersion::class)]
    #[ORM\JoinColumn(name: 'version_id', nullable: false)]
    #[Groups(['document_public_link:read'])]
    private ?DocumentVersion $version = null;

    /** sha256 hex du jeton — jamais le clair. N'est délibérément pas dans `document_public_link:read`. */
    #[ORM\Column(name: 'token_hash', length: 64, unique: true)]
    private string $tokenHash;

    #[ORM\Column(name: 'expires_at', type: 'datetime_immutable')]
    #[Groups(['document_public_link:read'])]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(name: 'revoked_at', type: 'datetime_immutable', nullable: true)]
    #[Groups(['document_public_link:read'])]
    private ?\DateTimeImmutable $revokedAt = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(name: 'created_by_id', nullable: false)]
    #[Groups(['document_public_link:read'])]
    private ?Utilisateur $createdBy = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    #[Groups(['document_public_link:read'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'access_count')]
    #[Groups(['document_public_link:read'])]
    private int $accessCount = 0;

    #[ORM\Column(name: 'last_accessed_at', type: 'datetime_immutable', nullable: true)]
    #[Groups(['document_public_link:read'])]
    private ?\DateTimeImmutable $lastAccessedAt = null;

    public function __construct(
        Document $document,
        DocumentVersion $version,
        string $tokenHash,
        \DateTimeImmutable $expiresAt,
        Utilisateur $createdBy,
    ) {
        $this->id = Uuid::v4();
        $this->document = $document;
        $this->version = $version;
        $this->tokenHash = $tokenHash;
        $this->expiresAt = $expiresAt;
        $this->createdBy = $createdBy;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getDocument(): ?Document
    {
        return $this->document;
    }

    public function getVersion(): ?DocumentVersion
    {
        return $this->version;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function revoke(\DateTimeImmutable $maintenant): self
    {
        $this->revokedAt = $maintenant;

        return $this;
    }

    public function isValid(\DateTimeImmutable $maintenant): bool
    {
        return $this->revokedAt === null && $this->expiresAt > $maintenant;
    }

    public function getCreatedBy(): ?Utilisateur
    {
        return $this->createdBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getAccessCount(): int
    {
        return $this->accessCount;
    }

    public function getLastAccessedAt(): ?\DateTimeImmutable
    {
        return $this->lastAccessedAt;
    }

    public function recordAccess(\DateTimeImmutable $maintenant): self
    {
        ++$this->accessCount;
        $this->lastAccessedAt = $maintenant;

        return $this;
    }
}
