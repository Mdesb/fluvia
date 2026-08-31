<?php

declare(strict_types=1);

namespace App\Dms\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Dms\Enum\DocumentCategory;
use App\Dms\Enum\DocumentStatus;
use App\Dms\Filter\RetentionStatusFilter;
use App\Dms\Processor\DeleteDocumentProcessor;
use App\Dms\Processor\RenameDocumentProcessor;
use App\Dms\Processor\ReplaceDocumentVersionProcessor;
use App\Dms\Processor\SetRetentionProcessor;
use App\Dms\Processor\UploadDocumentProcessor;
use App\Dms\Service\RetentionStatusCalculator;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Document versionné, cloisonné par établissement (spec-dms.md §5, plan-dms.md §1). `currentVersion`
 * est **nullable au niveau schéma** (contrainte technique de FK circulaire avec `DocumentVersion`,
 * plan §0.1) mais l'invariant métier « toujours renseigné » est garanti par construction :
 * `UploadDocumentHandler` crée `Document` + `DocumentVersion` v1 dans une seule transaction, et aucune
 * opération de lecture n'est jamais exposée pendant cette fenêtre — un `Document` n'est donc jamais
 * observable avec `currentVersion = null` depuis l'extérieur du service (défense en profondeur :
 * `UploadDocumentHandlerTest` vérifie l'invariant immédiatement après l'appel).
 *
 * `establishment` est **immuable** après création (RG-DMS-04) — non exposé dans `document:write`, et
 * gardé en ORM direct par `App\Dms\Doctrine\DocumentIntegrityListener`.
 */
#[ORM\Entity]
#[ORM\Table(name: 'dms_document')]
#[ORM\Index(columns: ['establishment_id', 'category'], name: 'IDX_DMS_DOCUMENT_ETAB_CATEGORY')]
#[ORM\Index(columns: ['establishment_id', 'status'], name: 'IDX_DMS_DOCUMENT_ETAB_STATUS')]
// La revue des documents arrivant a echeance de conservation (RGPD) balaie cette colonne.
#[ORM\Index(columns: ['retain_until'], name: 'idx_dms_document_retain_until')]
#[ApiResource(
    shortName: 'Document',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'dms.read')"),
        new Get(security: "is_granted('PERM', 'dms.read')"),
        // Upload initial (multipart) — corps lu manuellement dans le processor (`input: false`, même
        // idiome que le reste du dépôt, cf. `App\Vente\Service\LecteurCorps` pour le JSON) : aucun
        // précédent de DTO d'entrée auto-désérialisé par API Platform sur ce dépôt, y compris pour du
        // JSON simple — préférer l'idiome existant à une première expérimentation multipart risquée.
        new Post(
            uriTemplate: '/documents',
            inputFormats: ['multipart' => ['multipart/form-data']],
            read: false,
            input: false,
            security: "is_granted('PERM', 'dms.write')",
            processor: UploadDocumentProcessor::class,
            normalizationContext: ['groups' => ['document:read']],
        ),
        // Renommer / modifier les métadonnées — jamais establishment/sourceModule/currentVersion.
        new Patch(
            security: "is_granted('PERM', 'dms.write')",
            denormalizationContext: ['groups' => ['document:write']],
            processor: RenameDocumentProcessor::class,
        ),
        // Remplacer la version courante (multipart, fichier seul) — RG-DMS-18/19.
        new Post(
            uriTemplate: '/documents/{id}/replace-version',
            inputFormats: ['multipart' => ['multipart/form-data']],
            read: true,
            input: false,
            security: "is_granted('PERM', 'dms.write')",
            processor: ReplaceDocumentVersionProcessor::class,
            normalizationContext: ['groups' => ['document:read']],
        ),
        // Attacher/prolonger/lever une politique de rétention — RG-DMS-16.
        new Post(
            uriTemplate: '/documents/{id}/retention',
            read: true,
            input: false,
            security: "is_granted('PERM', 'dms.manage_retention')",
            processor: SetRetentionProcessor::class,
            normalizationContext: ['groups' => ['document:read']],
        ),
        // Suppression logique — refusée (409) si rétention active (RG-DMS-13).
        new Delete(
            security: "is_granted('PERM', 'dms.delete')",
            processor: DeleteDocumentProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['document:read']],
    denormalizationContext: ['groups' => ['document:write']],
)]
#[ApiFilter(SearchFilter::class, properties: ['establishment' => 'exact', 'category' => 'exact', 'status' => 'exact', 'title' => 'partial'])]
#[ApiFilter(RetentionStatusFilter::class)]
class Document
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['document:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(name: 'establishment_id', nullable: false)]
    #[Groups(['document:read'])]
    private ?Etablissement $establishment = null;

    #[ORM\Column(length: 30, enumType: DocumentCategory::class)]
    #[Groups(['document:read', 'document:write'])]
    private DocumentCategory $category;

    #[ORM\Column(length: 255)]
    #[Groups(['document:read', 'document:write'])]
    private string $title = '';

    /** @var list<string>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['document:read', 'document:write'])]
    private ?array $tags = null;

    #[ORM\Column(name: 'source_module', length: 60, nullable: true)]
    #[Groups(['document:read'])]
    private ?string $sourceModule = null;

    #[ORM\ManyToOne(targetEntity: DocumentVersion::class)]
    #[ORM\JoinColumn(name: 'current_version_id', nullable: true)]
    #[Groups(['document:read'])]
    private ?DocumentVersion $currentVersion = null;

    #[ORM\Column(length: 10, enumType: DocumentStatus::class, options: ['default' => 'active'])]
    #[Groups(['document:read'])]
    private DocumentStatus $status = DocumentStatus::Active;

    #[ORM\ManyToOne(targetEntity: RetentionPolicy::class)]
    #[ORM\JoinColumn(name: 'retention_policy_id', nullable: true)]
    #[Groups(['document:read'])]
    private ?RetentionPolicy $retentionPolicy = null;

    #[ORM\Column(name: 'retain_until', type: 'date_immutable', nullable: true)]
    #[Groups(['document:read'])]
    private ?\DateTimeImmutable $retainUntil = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(name: 'created_by_id', nullable: true)]
    #[Groups(['document:read'])]
    private ?Utilisateur $createdBy = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    #[Groups(['document:read'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    #[Groups(['document:read'])]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'deleted_at', type: 'datetime_immutable', nullable: true)]
    #[Groups(['document:read'])]
    private ?\DateTimeImmutable $deletedAt = null;

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

    /** À n'appeler qu'à la création (RG-DMS-04) — `DocumentIntegrityListener` garde l'ORM direct. */
    public function setEstablishment(?Etablissement $establishment): self
    {
        $this->establishment = $establishment;

        return $this;
    }

    public function getCategory(): DocumentCategory
    {
        return $this->category;
    }

    public function setCategory(DocumentCategory $category): self
    {
        $this->category = $category;

        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = $title;

        return $this;
    }

    /** @return list<string>|null */
    public function getTags(): ?array
    {
        return $this->tags;
    }

    /** @param list<string>|null $tags */
    public function setTags(?array $tags): self
    {
        $this->tags = $tags;

        return $this;
    }

    public function getSourceModule(): ?string
    {
        return $this->sourceModule;
    }

    public function setSourceModule(?string $sourceModule): self
    {
        $this->sourceModule = $sourceModule;

        return $this;
    }

    public function getCurrentVersion(): ?DocumentVersion
    {
        return $this->currentVersion;
    }

    public function setCurrentVersion(?DocumentVersion $currentVersion): self
    {
        $this->currentVersion = $currentVersion;

        return $this;
    }

    public function getStatus(): DocumentStatus
    {
        return $this->status;
    }

    public function setStatus(DocumentStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getRetentionPolicy(): ?RetentionPolicy
    {
        return $this->retentionPolicy;
    }

    public function setRetentionPolicy(?RetentionPolicy $retentionPolicy): self
    {
        $this->retentionPolicy = $retentionPolicy;

        return $this;
    }

    public function getRetainUntil(): ?\DateTimeImmutable
    {
        return $this->retainUntil;
    }

    public function setRetainUntil(?\DateTimeImmutable $retainUntil): self
    {
        $this->retainUntil = $retainUntil;

        return $this;
    }

    /** Champ calculé (RG-DMS-12) — jamais persisté, jamais recalculé implicitement ailleurs. */
    #[Groups(['document:read'])]
    public function getRetentionStatus(): string
    {
        return (new RetentionStatusCalculator())->statusFor($this->retainUntil)->value;
    }

    public function getCreatedBy(): ?Utilisateur
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?Utilisateur $createdBy): self
    {
        $this->createdBy = $createdBy;

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

    public function touch(): self
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getDeletedAt(): ?\DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function setDeletedAt(?\DateTimeImmutable $deletedAt): self
    {
        $this->deletedAt = $deletedAt;

        return $this;
    }
}
