<?php

declare(strict_types=1);

namespace App\Finance\Treasury\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Compta\Entity\LigneEcriture;
use App\Finance\Treasury\Enum\BankStatementLineStatus;
use App\Finance\Treasury\State\AddManualStatementLineProcessor;
use App\Finance\Treasury\State\ConfirmReconciliationProcessor;
use App\Finance\Treasury\State\IgnoreStatementLineProcessor;
use App\Finance\Treasury\State\ReconciliationSuggestionProvider;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Ligne d'un relevé bancaire importé (lot FIN-4, RG-TRE-02/03/04) — jamais une `LigneEcriture` : un
 * fait bancaire externe non comptable, que le rapprochement associe (`matchedLedgerLine`) à une ligne
 * d'écriture déjà scellée sans jamais recomptabiliser (§0.6 du plan).
 *
 * Convention de signe (§0.7 du plan, source d'erreur classique documentée explicitement) : positif =
 * crédit relevé (entrée d'argent, le 512 comptable **augmente** — convention débit) ; négatif = débit
 * relevé (sortie).
 */
#[ORM\Entity]
#[ORM\Table(name: 'finance_treasury_bank_statement_line')]
#[ORM\Index(columns: ['statement_import_id', 'operation_date'], name: 'idx_treasury_statement_line_import_date')]
#[ORM\Index(columns: ['status'], name: 'idx_treasury_statement_line_status')]
#[ApiResource(
    shortName: 'BankStatementLine',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'finance.read')"),
        new Get(security: "is_granted('PERM', 'finance.read')"),
        new Post(
            security: "is_granted('PERM', 'finance.treasury_import_statement')",
            processor: AddManualStatementLineProcessor::class,
        ),
        new GetCollection(
            uriTemplate: '/finance/treasury/statement-lines/{id}/suggestions',
            security: "is_granted('PERM', 'finance.read')",
            provider: ReconciliationSuggestionProvider::class,
        ),
        new Post(
            uriTemplate: '/finance/treasury/statement-lines/{id}/reconcile',
            read: true,
            input: false,
            security: "is_granted('PERM', 'finance.treasury_reconcile')",
            processor: ConfirmReconciliationProcessor::class,
        ),
        new Post(
            uriTemplate: '/finance/treasury/statement-lines/{id}/ignore',
            read: true,
            input: false,
            security: "is_granted('PERM', 'finance.treasury_reconcile')",
            processor: IgnoreStatementLineProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['bank_statement_line:read']],
    denormalizationContext: ['groups' => ['bank_statement_line:write']],
)]
#[ApiFilter(SearchFilter::class, properties: ['status' => 'exact', 'statementImport' => 'exact', 'statementImport.bankAccount' => 'exact'])]
class BankStatementLine
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['bank_statement_line:read', 'bank_statement_import:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: BankStatementImport::class)]
    #[ORM\JoinColumn(name: 'statement_import_id', nullable: false)]
    #[Assert\NotNull]
    #[Groups(['bank_statement_line:read', 'bank_statement_line:write'])]
    private ?BankStatementImport $statementImport = null;

    // `date_immutable` (cohérent avec le reste du dépôt, `\DateTime` mutable jamais utilisé ailleurs) :
    // `Doctrine\DBAL\Types\DateType::convertToDatabaseValue()` exige strictement une instance
    // `\DateTime`, jamais `\DateTimeImmutable` (`InvalidType` sinon) — `date` seul y aurait exposé.
    #[ORM\Column(name: 'operation_date', type: 'date_immutable')]
    #[Assert\NotNull]
    #[Groups(['bank_statement_line:read', 'bank_statement_line:write'])]
    private ?\DateTimeImmutable $operationDate = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Groups(['bank_statement_line:read', 'bank_statement_line:write'])]
    private string $label = '';

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    #[Assert\NotBlank]
    #[Groups(['bank_statement_line:read', 'bank_statement_line:write'])]
    private string $amount = '0.00';

    #[ORM\Column(length: 140, nullable: true)]
    #[Groups(['bank_statement_line:read', 'bank_statement_line:write'])]
    private ?string $reference = null;

    #[ORM\Column(length: 10, enumType: BankStatementLineStatus::class, options: ['default' => 'unmatched'])]
    #[Groups(['bank_statement_line:read'])]
    private BankStatementLineStatus $status = BankStatementLineStatus::Unmatched;

    /** Cache de suggestion non ambiguë (§0.7 du plan) — jamais appliquée automatiquement. */
    #[ORM\ManyToOne(targetEntity: LigneEcriture::class)]
    #[ORM\JoinColumn(name: 'suggested_ledger_line_id', nullable: true)]
    #[Groups(['bank_statement_line:read'])]
    private ?LigneEcriture $suggestedLedgerLine = null;

    /** `LigneEcriture` (512), pas `EcritureComptable` — précision nécessaire à la ligne exacte, §1 du plan. */
    #[ORM\ManyToOne(targetEntity: LigneEcriture::class)]
    #[ORM\JoinColumn(name: 'matched_ledger_line_id', nullable: true)]
    #[Groups(['bank_statement_line:read'])]
    private ?LigneEcriture $matchedLedgerLine = null;

    /** Copié depuis `LettrageEcriture.reconciliationCode` à la confirmation (§0.6 du plan). */
    #[ORM\Column(name: 'reconciliation_code', length: 36, nullable: true)]
    #[Groups(['bank_statement_line:read'])]
    private ?string $reconciliationCode = null;

    #[ORM\Column(name: 'ignored_reason', type: 'text', nullable: true)]
    #[Groups(['bank_statement_line:read'])]
    private ?string $ignoredReason = null;

    /** Garde d'idempotence de `treasury.discrepancy_detected` (§0.9 du plan, CA-6). */
    #[ORM\Column(name: 'discrepancy_notified_at', type: 'datetime_immutable', nullable: true)]
    #[Groups(['bank_statement_line:read'])]
    private ?\DateTimeImmutable $discrepancyNotifiedAt = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    #[Groups(['bank_statement_line:read'])]
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

    public function getStatementImport(): ?BankStatementImport
    {
        return $this->statementImport;
    }

    public function setStatementImport(?BankStatementImport $statementImport): self
    {
        $this->statementImport = $statementImport;

        return $this;
    }

    public function getOperationDate(): ?\DateTimeImmutable
    {
        return $this->operationDate;
    }

    public function setOperationDate(?\DateTimeImmutable $operationDate): self
    {
        $this->operationDate = $operationDate;

        return $this;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function setAmount(string $amount): self
    {
        $this->amount = $amount;

        return $this;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function setReference(?string $reference): self
    {
        $this->reference = $reference;

        return $this;
    }

    public function getStatus(): BankStatementLineStatus
    {
        return $this->status;
    }

    public function setStatus(BankStatementLineStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getSuggestedLedgerLine(): ?LigneEcriture
    {
        return $this->suggestedLedgerLine;
    }

    public function setSuggestedLedgerLine(?LigneEcriture $suggestedLedgerLine): self
    {
        $this->suggestedLedgerLine = $suggestedLedgerLine;

        return $this;
    }

    public function getMatchedLedgerLine(): ?LigneEcriture
    {
        return $this->matchedLedgerLine;
    }

    public function setMatchedLedgerLine(?LigneEcriture $matchedLedgerLine): self
    {
        $this->matchedLedgerLine = $matchedLedgerLine;

        return $this;
    }

    public function getReconciliationCode(): ?string
    {
        return $this->reconciliationCode;
    }

    public function setReconciliationCode(?string $reconciliationCode): self
    {
        $this->reconciliationCode = $reconciliationCode;

        return $this;
    }

    public function getIgnoredReason(): ?string
    {
        return $this->ignoredReason;
    }

    public function setIgnoredReason(?string $ignoredReason): self
    {
        $this->ignoredReason = $ignoredReason;

        return $this;
    }

    public function getDiscrepancyNotifiedAt(): ?\DateTimeImmutable
    {
        return $this->discrepancyNotifiedAt;
    }

    public function setDiscrepancyNotifiedAt(?\DateTimeImmutable $discrepancyNotifiedAt): self
    {
        $this->discrepancyNotifiedAt = $discrepancyNotifiedAt;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
