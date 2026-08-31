<?php

declare(strict_types=1);

namespace App\Dms\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Version d'un document — **append-only** (RG-DMS-17/18) : `fileHash`/`sizeBytes`/`mimeType`/
 * `storageKey`/`uploadedBy`/`uploadedAt` sont figés à la création et ne sont jamais modifiés. Gardé au
 * niveau ORM par `App\Dms\Doctrine\DocumentIntegrityListener` (préUpdate/preRemove rejettent toute
 * écriture sur une instance déjà persistée), au-delà de la simple absence de `Post`/`Patch`/`Delete`
 * côté API (même patron que `App\Ocr\Entity\ExtractionAttempt`, lecture seule stricte).
 *
 * `purgedAt` (ajout au-delà du littéral de la spec, plan-dms.md §14 pt.10) distingue en base une
 * version historique intacte d'une version dont le contenu physique a été retiré par
 * `PurgeDocumentsCommand` — les métadonnées restent pour l'audit (RG-DMS-14), seul le pointeur
 * `storageKey` cesse de désigner un contenu lisible.
 */
#[ORM\Entity]
#[ORM\Table(name: 'dms_document_version')]
// Deux index poses par migration le 22/08 et jamais declares : la deduplication par
// empreinte, et le tri chronologique des versions.
#[ORM\Index(columns: ['file_hash'], name: 'idx_dms_version_file_hash')]
#[ORM\Index(columns: ['uploaded_at'], name: 'idx_dms_version_uploaded_at')]
#[ORM\UniqueConstraint(name: 'uniq_dms_version_document_number', columns: ['document_id', 'version_number'])]
#[ORM\UniqueConstraint(name: 'uniq_dms_version_storage_key', columns: ['storage_key'])]
#[ApiResource(
    shortName: 'DocumentVersion',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'dms.read')"),
        new Get(security: "is_granted('PERM', 'dms.read')"),
        // Aucun Post/Patch/Delete exposé (append-only, RG-DMS-17) — lecture seule stricte.
    ],
    normalizationContext: ['groups' => ['document_version:read']],
)]
#[ApiFilter(SearchFilter::class, properties: ['document' => 'exact'])]
class DocumentVersion
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['document_version:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Document::class)]
    #[ORM\JoinColumn(name: 'document_id', nullable: false)]
    #[Groups(['document_version:read'])]
    private ?Document $document = null;

    #[ORM\Column(name: 'version_number')]
    #[Groups(['document_version:read', 'document:read'])]
    private int $versionNumber;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'previous_version_id', nullable: true)]
    #[Groups(['document_version:read'])]
    private ?self $previousVersion = null;

    #[ORM\Column(name: 'storage_key', length: 190, unique: true)]
    private string $storageKey;

    // `fixed` = CHAR et non VARCHAR : une empreinte sha256 fait toujours 64 caracteres, et la
    // base le savait deja. Sans ce mot, Doctrine voulait la retrograder en VARCHAR.
    #[ORM\Column(name: 'file_hash', length: 64, options: ['fixed' => true])]
    #[Groups(['document_version:read'])]
    private string $fileHash;

    #[ORM\Column(name: 'size_bytes')]
    #[Groups(['document_version:read', 'document:read'])]
    private int $sizeBytes;

    #[ORM\Column(name: 'mime_type', length: 127)]
    #[Groups(['document_version:read'])]
    private string $mimeType;

    #[ORM\Column(name: 'original_filename', length: 255)]
    #[Groups(['document_version:read', 'document:read'])]
    private string $originalFilename;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(name: 'uploaded_by_id', nullable: true)]
    #[Groups(['document_version:read'])]
    private ?Utilisateur $uploadedBy = null;

    #[ORM\Column(name: 'uploaded_at', type: 'datetime_immutable')]
    #[Groups(['document_version:read'])]
    private \DateTimeImmutable $uploadedAt;

    /** Non nul une fois la purge physique passée (RG-DMS-15, plan §14 pt.10) — métadonnées conservées. */
    #[ORM\Column(name: 'purged_at', type: 'datetime_immutable', nullable: true)]
    #[Groups(['document_version:read'])]
    private ?\DateTimeImmutable $purgedAt = null;

    public function __construct(
        Document $document,
        int $versionNumber,
        ?self $previousVersion,
        string $storageKey,
        string $fileHash,
        int $sizeBytes,
        string $mimeType,
        string $originalFilename,
        ?Utilisateur $uploadedBy,
    ) {
        $this->id = Uuid::v4();
        $this->document = $document;
        $this->versionNumber = $versionNumber;
        $this->previousVersion = $previousVersion;
        $this->storageKey = $storageKey;
        $this->fileHash = $fileHash;
        $this->sizeBytes = $sizeBytes;
        $this->mimeType = $mimeType;
        $this->originalFilename = $originalFilename;
        $this->uploadedBy = $uploadedBy;
        $this->uploadedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getDocument(): ?Document
    {
        return $this->document;
    }

    public function getVersionNumber(): int
    {
        return $this->versionNumber;
    }

    public function getPreviousVersion(): ?self
    {
        return $this->previousVersion;
    }

    public function getStorageKey(): string
    {
        return $this->storageKey;
    }

    public function getFileHash(): string
    {
        return $this->fileHash;
    }

    public function getSizeBytes(): int
    {
        return $this->sizeBytes;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function getOriginalFilename(): string
    {
        return $this->originalFilename;
    }

    public function getUploadedBy(): ?Utilisateur
    {
        return $this->uploadedBy;
    }

    public function getUploadedAt(): \DateTimeImmutable
    {
        return $this->uploadedAt;
    }

    public function getPurgedAt(): ?\DateTimeImmutable
    {
        return $this->purgedAt;
    }

    /** Appelé uniquement par `PurgeDocumentsCommand` — n'est pas une écriture ORM « normale ». */
    public function markPurged(\DateTimeImmutable $maintenant): self
    {
        $this->purgedAt = $maintenant;

        return $this;
    }
}
