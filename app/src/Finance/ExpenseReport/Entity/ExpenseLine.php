<?php

declare(strict_types=1);

namespace App\Finance\ExpenseReport\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Compta\Entity\TauxTva;
use App\Finance\ExpenseReport\State\ExpenseLineProcessor;
use App\Ocr\Entity\ExtractionAttempt;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Ligne de dépense d'une `ExpenseReport` (RG-EXP-02) : créée/éditée **séparément** de la note (même
 * patron que `SupplierInvoiceLine`, plan §2), figée dès que la note quitte `draft`
 * (`ExpenseLineProcessor`, D8). `amountExclTax`/`vatAmount` toujours recalculés serveur. Le
 * justificatif (`receipt*`) est **nullable en base** — requis seulement au moment de `/submit`
 * (RG-EXP-02.1, CA-1), jamais bloquant à la création/édition en brouillon.
 */
#[ORM\Entity]
#[ORM\Table(name: 'finance_expense_line')]
#[ORM\Index(columns: ['expense_report_id'], name: 'idx_expense_line_report')]
#[ApiResource(
    shortName: 'ExpenseLine',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'finance.read') or is_granted('PERM', 'finance.expense_report_read_own') or is_granted('PERM', 'finance.expense_report_post_to_ledger') or is_granted('PERM', 'finance.manage')"),
        new Get(security: "is_granted('PERM', 'finance.read') or is_granted('PERM', 'finance.expense_report_read_own') or is_granted('PERM', 'finance.expense_report_post_to_ledger') or is_granted('PERM', 'finance.manage')"),
        new Post(
            security: "is_granted('PERM', 'finance.expense_report_submit')",
            processor: ExpenseLineProcessor::class,
        ),
        new Patch(
            security: "is_granted('PERM', 'finance.expense_report_submit')",
            processor: ExpenseLineProcessor::class,
        ),
        new Delete(
            security: "is_granted('PERM', 'finance.expense_report_submit')",
            processor: ExpenseLineProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['expense_line:read']],
    denormalizationContext: ['groups' => ['expense_line:write']],
)]
#[ApiFilter(SearchFilter::class, properties: ['expenseReport' => 'exact'])]
class ExpenseLine
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['expense_line:read', 'expense_report:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: ExpenseReport::class, inversedBy: 'lines')]
    #[ORM\JoinColumn(name: 'expense_report_id', nullable: false)]
    #[Assert\NotNull]
    #[Groups(['expense_line:read', 'expense_line:write'])]
    private ?ExpenseReport $expenseReport = null;

    #[ORM\Column(name: 'expense_nature_code', length: 64)]
    #[Assert\NotBlank]
    #[Groups(['expense_line:read', 'expense_line:write', 'expense_report:read'])]
    private string $expenseNatureCode = '';

    #[ORM\Column(name: 'expense_date', type: 'date_immutable')]
    #[Assert\NotNull]
    #[Groups(['expense_line:read', 'expense_line:write', 'expense_report:read'])]
    private ?\DateTimeImmutable $expenseDate = null;

    #[ORM\Column(name: 'amount_incl_tax', type: 'decimal', precision: 12, scale: 2)]
    #[Assert\Positive]
    #[Groups(['expense_line:read', 'expense_line:write', 'expense_report:read'])]
    private string $amountInclTax = '0.00';

    #[ORM\ManyToOne(targetEntity: TauxTva::class)]
    #[ORM\JoinColumn(name: 'vat_rate_id', nullable: true)]
    #[Groups(['expense_line:read', 'expense_line:write', 'expense_report:read'])]
    private ?TauxTva $vatRate = null;

    #[ORM\Column(name: 'amount_excl_tax', type: 'decimal', precision: 12, scale: 2)]
    #[Groups(['expense_line:read', 'expense_report:read'])]
    private string $amountExclTax = '0.00';

    #[ORM\Column(name: 'vat_amount', type: 'decimal', precision: 12, scale: 2)]
    #[Groups(['expense_line:read', 'expense_report:read'])]
    private string $vatAmount = '0.00';

    #[ORM\Column(name: 'receipt_file_name', length: 255, nullable: true)]
    #[Groups(['expense_line:read', 'expense_line:write', 'expense_report:read'])]
    private ?string $receiptFileName = null;

    #[ORM\Column(name: 'receipt_mime_type', length: 100, nullable: true)]
    #[Groups(['expense_line:read', 'expense_line:write', 'expense_report:read'])]
    private ?string $receiptMimeType = null;

    #[ORM\Column(name: 'receipt_size', type: 'integer', nullable: true)]
    #[Groups(['expense_line:read', 'expense_line:write', 'expense_report:read'])]
    private ?int $receiptSize = null;

    #[ORM\Column(name: 'receipt_url', length: 500, nullable: true)]
    #[Groups(['expense_line:read', 'expense_line:write', 'expense_report:read'])]
    private ?string $receiptUrl = null;

    #[ORM\ManyToOne(targetEntity: ExtractionAttempt::class)]
    #[ORM\JoinColumn(name: 'ocr_extraction_id', nullable: true)]
    #[Groups(['expense_line:read', 'expense_line:write'])]
    private ?ExtractionAttempt $ocrExtraction = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['expense_line:read', 'expense_line:write', 'expense_report:read'])]
    private ?string $description = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getExpenseReport(): ?ExpenseReport
    {
        return $this->expenseReport;
    }

    public function setExpenseReport(?ExpenseReport $expenseReport): self
    {
        $this->expenseReport = $expenseReport;

        return $this;
    }

    public function getExpenseNatureCode(): string
    {
        return $this->expenseNatureCode;
    }

    public function setExpenseNatureCode(string $expenseNatureCode): self
    {
        $this->expenseNatureCode = $expenseNatureCode;

        return $this;
    }

    public function getExpenseDate(): ?\DateTimeImmutable
    {
        return $this->expenseDate;
    }

    public function setExpenseDate(?\DateTimeImmutable $expenseDate): self
    {
        $this->expenseDate = $expenseDate;

        return $this;
    }

    public function getAmountInclTax(): string
    {
        return $this->amountInclTax;
    }

    public function setAmountInclTax(string $amountInclTax): self
    {
        $this->amountInclTax = $amountInclTax;

        return $this;
    }

    public function getVatRate(): ?TauxTva
    {
        return $this->vatRate;
    }

    public function setVatRate(?TauxTva $vatRate): self
    {
        $this->vatRate = $vatRate;

        return $this;
    }

    public function getAmountExclTax(): string
    {
        return $this->amountExclTax;
    }

    public function getVatAmount(): string
    {
        return $this->vatAmount;
    }

    public function getReceiptFileName(): ?string
    {
        return $this->receiptFileName;
    }

    public function setReceiptFileName(?string $receiptFileName): self
    {
        $this->receiptFileName = $receiptFileName;

        return $this;
    }

    public function getReceiptMimeType(): ?string
    {
        return $this->receiptMimeType;
    }

    public function setReceiptMimeType(?string $receiptMimeType): self
    {
        $this->receiptMimeType = $receiptMimeType;

        return $this;
    }

    public function getReceiptSize(): ?int
    {
        return $this->receiptSize;
    }

    public function setReceiptSize(?int $receiptSize): self
    {
        $this->receiptSize = $receiptSize;

        return $this;
    }

    public function getReceiptUrl(): ?string
    {
        return $this->receiptUrl;
    }

    public function setReceiptUrl(?string $receiptUrl): self
    {
        $this->receiptUrl = $receiptUrl;

        return $this;
    }

    public function getOcrExtraction(): ?ExtractionAttempt
    {
        return $this->ocrExtraction;
    }

    public function setOcrExtraction(?ExtractionAttempt $ocrExtraction): self
    {
        $this->ocrExtraction = $ocrExtraction;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    /**
     * Recalcule HT/TVA serveur (RG-EXP §0.5 point 3, jamais fournis par le client) :
     * - `vatRate` présent -> `amountExclTax = amountInclTax / (1 + taux)`, `vatAmount = amountInclTax - amountExclTax`.
     * - `vatRate` absent -> aucune TVA déduite, `amountExclTax = amountInclTax`, `vatAmount = 0.00`
     *   (pas de taux « emprunté » au mapping — choix conservateur, §0.5 point 3 du plan).
     */
    public function recalculer(): self
    {
        $ttc = (float) $this->amountInclTax;

        if ($this->vatRate instanceof TauxTva) {
            $tauxPourcent = (float) $this->vatRate->getTaux();
            $ht = $tauxPourcent > 0 ? $ttc / (1 + $tauxPourcent / 100) : $ttc;
            $tva = $ttc - $ht;
        } else {
            $ht = $ttc;
            $tva = 0.0;
        }

        $this->amountExclTax = number_format(round($ht, 2), 2, '.', '');
        $this->vatAmount = number_format(round($tva, 2), 2, '.', '');

        return $this;
    }
}
