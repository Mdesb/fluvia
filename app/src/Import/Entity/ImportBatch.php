<?php

declare(strict_types=1);

namespace App\Import\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Import\Enum\ImportBatchStatus;
use App\Import\Enum\ImportType;
use App\Import\State\ApplyImportBatchProcessor;
use App\Import\State\RevertImportBatchProcessor;
use App\Import\State\ValidateImportBatchProcessor;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Lot de reprise initiale (`App\Import`, plan-import-i1.md §0/§1, SPEC-REPRISE-INITIALE.md §2) — un
 * import est un OBJET, pas une action : il porte le fichier, son verdict, et ce qu'il a créé.
 *
 * Généralise `App\Finance\Treasury\Entity\BankStatementImport` (`contentHash`, statut enum, message(s)
 * d'erreur porté par l'objet) avec **deux écarts assumés** (§0.1/§0.2 du plan) :
 * 1. `content` est ici **persisté** (LONGTEXT, base64) — le précédent le rend transitoire. Un import
 *    contesté six mois plus tard doit pouvoir se rejuger sur ce qui l'a produit, pas seulement son
 *    résultat.
 * 2. Deux temps stricts (D98) : `POST /imports` valide TOUT sans écrire en base métier
 *    (`status = validated|rejected`) ; `POST /imports/{id}/appliquer` écrit, en une transaction
 *    (`status = applied`) ; `POST /imports/{id}/annuler` défait exactement ce que CE lot a créé
 *    (`status = reverted`).
 *
 * `establishment` n'apparaît **jamais** dans un groupe d'écriture (D41, §0.7) : les trois opérations
 * d'écriture sont chacune un `Processor` dédié qui appelle lui-même `persist()`/`flush()` — le
 * décorateur global `EstablishmentScopeWriteGuard` ne s'applique donc pas ici, et n'a de toute façon
 * rien à ignorer puisqu'aucune valeur cliente n'existe pour ce champ.
 *
 * `content` est exclu de la collection (métadonnées seules) et inclus uniquement sur `Get` et sur
 * `.../appliquer` (groupe `import_batch:read_content`, §2 du plan) — un fichier de reprise complet
 * n'a pas sa place dans une liste.
 *
 * @sans-suppression: un lot n'est jamais effacé, il est ANNULÉ (`.../annuler` → `status = reverted`,
 * qui défait ses lignes tout en gardant la trace). Le supprimer retirerait la preuve de ce qu'une
 * reprise a créé — or `content` est persisté précisément pour rejuger un import contesté (§0.1).
 */
#[ORM\Entity]
#[ORM\Table(name: 'import_batch')]
#[ORM\Index(columns: ['establishment_id'], name: 'idx_import_batch_establishment')]
#[ORM\Index(columns: ['establishment_id', 'content_hash'], name: 'idx_import_batch_hash')]
#[ORM\Index(columns: ['status'], name: 'idx_import_batch_status')]
#[ApiResource(
    shortName: 'Import',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'import.read')"),
        new Get(
            security: "is_granted('PERM', 'import.read')",
            normalizationContext: ['groups' => ['import_batch:read', 'import_batch:read_content']],
        ),
        new Post(
            security: "is_granted('PERM', 'import.create')",
            processor: ValidateImportBatchProcessor::class,
        ),
        new Post(
            uriTemplate: '/imports/{id}/appliquer',
            read: true,
            input: false,
            security: "is_granted('PERM', 'import.apply')",
            processor: ApplyImportBatchProcessor::class,
            normalizationContext: ['groups' => ['import_batch:read', 'import_batch:read_content']],
        ),
        new Post(
            uriTemplate: '/imports/{id}/annuler',
            read: true,
            input: false,
            security: "is_granted('PERM', 'import.revert')",
            processor: RevertImportBatchProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['import_batch:read']],
    denormalizationContext: ['groups' => ['import_batch:write']],
)]
#[ApiFilter(SearchFilter::class, properties: ['type' => 'exact', 'status' => 'exact', 'contentHash' => 'exact'])]
class ImportBatch
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['import_batch:read'])]
    private Uuid $id;

    /** Estampillé serveur par `ValidateImportBatchProcessor`, jamais depuis le corps (D41, §0.7). */
    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(name: 'establishment_id', nullable: false)]
    #[Groups(['import_batch:read'])]
    private ?Etablissement $establishment = null;

    #[ORM\Column(length: 20, enumType: ImportType::class)]
    #[Groups(['import_batch:read', 'import_batch:write'])]
    private ?ImportType $type = null;

    #[ORM\Column(name: 'file_name', length: 255, nullable: true)]
    #[Groups(['import_batch:read', 'import_batch:write'])]
    private ?string $fileName = null;

    #[ORM\Column(name: 'mime_type', length: 100, nullable: true)]
    #[Groups(['import_batch:read', 'import_batch:write'])]
    private ?string $mimeType = null;

    #[ORM\Column(name: 'file_size', type: Types::INTEGER, options: ['default' => 0])]
    #[Groups(['import_batch:read'])]
    private int $fileSize = 0;

    #[ORM\Column(name: 'content_hash', length: 64, nullable: true)]
    #[Groups(['import_batch:read'])]
    private ?string $contentHash = null;

    /**
     * Fichier source, base64, **conservé** (§0.1, écart assumé face à `BankStatementImport`) — `text`
     * (`Types::TEXT`, la plateforme MariaDB/Doctrine matérialise systématiquement en `LONGTEXT`, jamais
     * un `TEXT` nu limité à 64 Ko — vérifié sur la migration générée, D32).
     */
    #[ORM\Column(type: Types::TEXT)]
    #[Groups(['import_batch:read_content', 'import_batch:write'])]
    private string $content = '';

    /** Total annoncé par le client — réservé à `card_credits` (I2+), ignoré par `customers` (I1). */
    #[ORM\Column(name: 'expected_total', type: Types::DECIMAL, precision: 14, scale: 2, nullable: true)]
    #[Groups(['import_batch:read', 'import_batch:write'])]
    private ?string $expectedTotal = null;

    #[ORM\Column(length: 10, enumType: ImportBatchStatus::class, options: ['default' => 'pending'])]
    #[Groups(['import_batch:read'])]
    private ImportBatchStatus $status = ImportBatchStatus::Pending;

    #[ORM\Column(name: 'row_count', type: Types::INTEGER, options: ['default' => 0])]
    #[Groups(['import_batch:read'])]
    private int $rowCount = 0;

    /** @var array<int,string>|null ligne => message, la liste ENTIÈRE, jamais tronquée (D98). */
    #[ORM\Column(nullable: true)]
    #[Groups(['import_batch:read'])]
    private ?array $errors = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['import_batch:read'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(name: 'created_by_id', nullable: true)]
    #[Groups(['import_batch:read'])]
    private ?Utilisateur $createdBy = null;

    #[ORM\Column(name: 'applied_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Groups(['import_batch:read'])]
    private ?\DateTimeImmutable $appliedAt = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->createdAt = new \DateTimeImmutable();
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

    public function getType(): ?ImportType
    {
        return $this->type;
    }

    public function setType(?ImportType $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getFileName(): ?string
    {
        return $this->fileName;
    }

    public function setFileName(?string $fileName): self
    {
        $this->fileName = $fileName;

        return $this;
    }

    public function getMimeType(): ?string
    {
        return $this->mimeType;
    }

    public function setMimeType(?string $mimeType): self
    {
        $this->mimeType = $mimeType;

        return $this;
    }

    public function getFileSize(): int
    {
        return $this->fileSize;
    }

    public function setFileSize(int $fileSize): self
    {
        $this->fileSize = $fileSize;

        return $this;
    }

    public function getContentHash(): ?string
    {
        return $this->contentHash;
    }

    public function setContentHash(?string $contentHash): self
    {
        $this->contentHash = $contentHash;

        return $this;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function setContent(string $content): self
    {
        $this->content = $content;

        return $this;
    }

    public function getExpectedTotal(): ?string
    {
        return $this->expectedTotal;
    }

    public function setExpectedTotal(?string $expectedTotal): self
    {
        $this->expectedTotal = $expectedTotal;

        return $this;
    }

    public function getStatus(): ImportBatchStatus
    {
        return $this->status;
    }

    public function setStatus(ImportBatchStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getRowCount(): int
    {
        return $this->rowCount;
    }

    public function setRowCount(int $rowCount): self
    {
        $this->rowCount = $rowCount;

        return $this;
    }

    /** @return array<int,string>|null */
    public function getErrors(): ?array
    {
        return $this->errors;
    }

    /** @param array<int,string>|null $errors */
    public function setErrors(?array $errors): self
    {
        $this->errors = $errors;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
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

    public function getAppliedAt(): ?\DateTimeImmutable
    {
        return $this->appliedAt;
    }

    public function setAppliedAt(?\DateTimeImmutable $appliedAt): self
    {
        $this->appliedAt = $appliedAt;

        return $this;
    }
}
