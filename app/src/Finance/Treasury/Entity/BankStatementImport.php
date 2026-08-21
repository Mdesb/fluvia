<?php

declare(strict_types=1);

namespace App\Finance\Treasury\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Finance\Treasury\Enum\BankStatementImportFormat;
use App\Finance\Treasury\Enum\BankStatementImportStatus;
use App\Finance\Treasury\State\ImportBankStatementProcessor;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Import d'un relevé bancaire (lot FIN-4, US-TRE-02, RG-TRE-02) — `bankAccount` est l'ancre de
 * périmètre (chaîne à un saut, §0.2 point 2 du plan). Idempotence à deux niveaux (§0.5) : `contentHash`
 * (`UNIQUE(bank_account_id, content_hash)`, niveau fichier, CA-2) + déduplication applicative par ligne
 * (triplet date/montant/référence, niveau ligne, robuste à un fichier partiellement recouvrant).
 *
 * `content` est un champ **transitoire** (base64, jamais mappé Doctrine) : seul `contentHash` (empreinte
 * SHA-256) est persisté — même principe que l'attachement `SupplierInvoice`/`ExpenseReport`, référence/
 * empreinte, jamais le document brut en base (§3 du plan).
 */
#[ORM\Entity]
#[ORM\Table(name: 'finance_treasury_bank_statement_import')]
#[ORM\UniqueConstraint(name: 'uniq_treasury_statement_import_hash', columns: ['bank_account_id', 'content_hash'])]
#[ORM\Index(columns: ['bank_account_id'], name: 'idx_treasury_statement_import_bank_account')]
#[ApiResource(
    shortName: 'BankStatementImport',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'finance.read')"),
        new Get(security: "is_granted('PERM', 'finance.read')"),
        new Post(
            security: "is_granted('PERM', 'finance.treasury_import_statement')",
            processor: ImportBankStatementProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['bank_statement_import:read']],
    denormalizationContext: ['groups' => ['bank_statement_import:write']],
)]
#[ApiFilter(SearchFilter::class, properties: ['bankAccount' => 'exact', 'status' => 'exact'])]
class BankStatementImport
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['bank_statement_import:read', 'bank_statement_line:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: BankAccount::class)]
    #[ORM\JoinColumn(name: 'bank_account_id', nullable: false)]
    #[Assert\NotNull]
    #[Groups(['bank_statement_import:read', 'bank_statement_import:write'])]
    private ?BankAccount $bankAccount = null;

    #[ORM\Column(length: 8, enumType: BankStatementImportFormat::class)]
    #[Assert\NotNull]
    #[Groups(['bank_statement_import:read', 'bank_statement_import:write'])]
    private ?BankStatementImportFormat $format = null;

    #[ORM\Column(name: 'file_name', length: 255, nullable: true)]
    #[Groups(['bank_statement_import:read', 'bank_statement_import:write'])]
    private ?string $fileName = null;

    #[ORM\Column(name: 'file_mime_type', length: 100, nullable: true)]
    #[Groups(['bank_statement_import:read', 'bank_statement_import:write'])]
    private ?string $fileMimeType = null;

    #[ORM\Column(name: 'file_size', type: 'integer', nullable: true)]
    #[Groups(['bank_statement_import:read'])]
    private ?int $fileSize = null;

    #[ORM\Column(name: 'content_hash', length: 64, nullable: true)]
    #[Groups(['bank_statement_import:read'])]
    private ?string $contentHash = null;

    /** Contenu brut en base64 — champ transitoire (jamais mappé Doctrine), consommé par le processor. */
    #[Groups(['bank_statement_import:write'])]
    private ?string $content = null;

    // `datetime_immutable` (pas `datetime` littéral du plan §1) : cohérent avec le reste du dépôt
    // (`DateTimeImmutable` partout ailleurs) — corrige un `InvalidType` Doctrine constaté en test
    // (`setImportedAt(new \DateTimeImmutable())` face à une colonne `datetime` qui exige `\DateTime`).
    #[ORM\Column(name: 'imported_at', type: 'datetime_immutable')]
    #[Groups(['bank_statement_import:read'])]
    private \DateTimeImmutable $importedAt;

    #[ORM\Column(length: 10, enumType: BankStatementImportStatus::class, options: ['default' => 'imported'])]
    #[Groups(['bank_statement_import:read'])]
    private BankStatementImportStatus $status = BankStatementImportStatus::Imported;

    #[ORM\Column(name: 'error_message', type: 'text', nullable: true)]
    #[Groups(['bank_statement_import:read'])]
    private ?string $errorMessage = null;

    #[ORM\Column(name: 'lines_created', type: 'integer', options: ['default' => 0])]
    #[Groups(['bank_statement_import:read'])]
    private int $linesCreated = 0;

    #[ORM\Column(name: 'lines_skipped', type: 'integer', options: ['default' => 0])]
    #[Groups(['bank_statement_import:read'])]
    private int $linesSkipped = 0;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(name: 'created_by_id', nullable: true)]
    #[Groups(['bank_statement_import:read'])]
    private ?Utilisateur $createdBy = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->importedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getBankAccount(): ?BankAccount
    {
        return $this->bankAccount;
    }

    public function setBankAccount(?BankAccount $bankAccount): self
    {
        $this->bankAccount = $bankAccount;

        return $this;
    }

    public function getFormat(): ?BankStatementImportFormat
    {
        return $this->format;
    }

    public function setFormat(?BankStatementImportFormat $format): self
    {
        $this->format = $format;

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

    public function getFileMimeType(): ?string
    {
        return $this->fileMimeType;
    }

    public function setFileMimeType(?string $fileMimeType): self
    {
        $this->fileMimeType = $fileMimeType;

        return $this;
    }

    public function getFileSize(): ?int
    {
        return $this->fileSize;
    }

    public function setFileSize(?int $fileSize): self
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

    public function getContent(): ?string
    {
        return $this->content;
    }

    public function setContent(?string $content): self
    {
        $this->content = $content;

        return $this;
    }

    public function getImportedAt(): \DateTimeInterface
    {
        return $this->importedAt;
    }

    public function setImportedAt(\DateTimeInterface $importedAt): self
    {
        $this->importedAt = $importedAt;

        return $this;
    }

    public function getStatus(): BankStatementImportStatus
    {
        return $this->status;
    }

    public function setStatus(BankStatementImportStatus $status): self
    {
        $this->status = $status;

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

    public function getLinesCreated(): int
    {
        return $this->linesCreated;
    }

    public function setLinesCreated(int $linesCreated): self
    {
        $this->linesCreated = $linesCreated;

        return $this;
    }

    public function getLinesSkipped(): int
    {
        return $this->linesSkipped;
    }

    public function setLinesSkipped(int $linesSkipped): self
    {
        $this->linesSkipped = $linesSkipped;

        return $this;
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
}
