<?php

declare(strict_types=1);

namespace App\Finance\ExpenseReport\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Autorisation\Entity\DemandeEscalade;
use App\Compta\Entity\EcritureComptable;
use App\Compta\Entity\ProfilExploitant;
use App\Finance\ExpenseReport\Enum\ExpenseReportStatus;
use App\Finance\ExpenseReport\State\CreateExpenseReportProcessor;
use App\Finance\ExpenseReport\State\ExtractExpenseReceiptProcessor;
use App\Finance\ExpenseReport\State\FinalizeEscaladeExpenseReportProcessor;
use App\Finance\ExpenseReport\State\PostToLedgerExpenseReportProcessor;
use App\Finance\ExpenseReport\State\ReopenExpenseReportProcessor;
use App\Finance\ExpenseReport\State\SubmitExpenseReportProcessor;
use App\Organisation\Entity\Etablissement;
use App\Personnel\Entity\Employe;
use App\Securite\Entity\Utilisateur;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Note de frais (lot FIN-3, US-EXP-01 à 09) : `establishment` **direct** (même patron que
 * `SupplierInvoice`, plan §0.2) — ancre de périmètre et source du tenant d'événement (D6). La
 * validation (§0.3 du plan) est entièrement déléguée à `App\Autorisation::ServiceAutorisation`, jamais
 * un second moteur ; le déversement comptable (§0.5) est découplé de l'approbation métier.
 */
#[ORM\Entity]
#[ORM\Table(name: 'finance_expense_report')]
#[ORM\Index(columns: ['establishment_id', 'status'], name: 'idx_expense_report_establishment_status')]
#[ORM\Index(columns: ['employee_id'], name: 'idx_expense_report_employee')]
#[ApiResource(
    shortName: 'ExpenseReport',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'finance.read') or is_granted('PERM', 'finance.expense_report_read_own') or is_granted('PERM', 'finance.expense_report_post_to_ledger') or is_granted('PERM', 'finance.manage')"),
        new Get(security: "is_granted('PERM', 'finance.read') or is_granted('PERM', 'finance.expense_report_read_own') or is_granted('PERM', 'finance.expense_report_post_to_ledger') or is_granted('PERM', 'finance.manage')"),
        new Post(
            security: "is_granted('PERM', 'finance.expense_report_submit')",
            processor: CreateExpenseReportProcessor::class,
        ),
        new Patch(
            security: "is_granted('PERM', 'finance.expense_report_submit') and is_granted('EMPLOYE_SOI', object.getEmployee())",
            processor: CreateExpenseReportProcessor::class,
        ),
        new Post(
            uriTemplate: '/finance/expense-reports/extract',
            read: false,
            input: false,
            output: false,
            security: "is_granted('PERM', 'finance.expense_report_submit')",
            processor: ExtractExpenseReceiptProcessor::class,
        ),
        new Post(
            uriTemplate: '/finance/expense-reports/{id}/submit',
            read: true,
            input: false,
            security: "is_granted('PERM', 'finance.expense_report_submit') and is_granted('EMPLOYE_SOI', object.getEmployee())",
            processor: SubmitExpenseReportProcessor::class,
        ),
        new Post(
            uriTemplate: '/finance/expense-reports/{id}/reopen',
            read: true,
            input: false,
            security: "is_granted('PERM', 'finance.expense_report_submit') and is_granted('EMPLOYE_SOI', object.getEmployee())",
            processor: ReopenExpenseReportProcessor::class,
        ),
        new Post(
            uriTemplate: '/finance/expense-reports/{id}/finalize-escalade',
            read: true,
            input: false,
            security: "(is_granted('PERM', 'finance.expense_report_submit') and is_granted('EMPLOYE_SOI', object.getEmployee())) or is_granted('PERM', 'finance.expense_report_post_to_ledger')",
            processor: FinalizeEscaladeExpenseReportProcessor::class,
        ),
        new Post(
            uriTemplate: '/finance/expense-reports/{id}/post-to-ledger',
            read: true,
            input: false,
            security: "is_granted('PERM', 'finance.expense_report_post_to_ledger')",
            processor: PostToLedgerExpenseReportProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['expense_report:read']],
    denormalizationContext: ['groups' => ['expense_report:write']],
)]
#[ApiFilter(SearchFilter::class, properties: ['status' => 'exact', 'employee' => 'exact', 'businessProfile' => 'exact'])]
class ExpenseReport
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['expense_report:read', 'expense_line:read', 'reimbursement:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(name: 'establishment_id', nullable: false)]
    #[Assert\NotNull]
    #[Groups(['expense_report:read', 'expense_report:write'])]
    private ?Etablissement $establishment = null;

    #[ORM\ManyToOne(targetEntity: ProfilExploitant::class)]
    #[ORM\JoinColumn(name: 'business_profile_id', nullable: false)]
    #[Assert\NotNull]
    #[Groups(['expense_report:read', 'expense_report:write'])]
    private ?ProfilExploitant $businessProfile = null;

    #[ORM\ManyToOne(targetEntity: Employe::class)]
    #[ORM\JoinColumn(name: 'employee_id', nullable: false)]
    #[Assert\NotNull]
    #[Groups(['expense_report:read', 'expense_report:write'])]
    private ?Employe $employee = null;

    #[ORM\Column(length: 16, enumType: ExpenseReportStatus::class, options: ['default' => 'draft'])]
    #[Groups(['expense_report:read'])]
    private ExpenseReportStatus $status = ExpenseReportStatus::Draft;

    #[ORM\Column(name: 'total_amount', type: 'decimal', precision: 12, scale: 2, options: ['default' => '0.00'])]
    #[Groups(['expense_report:read'])]
    private string $totalAmount = '0.00';

    #[ORM\ManyToOne(targetEntity: DemandeEscalade::class)]
    #[ORM\JoinColumn(name: 'escalation_request_id', nullable: true)]
    #[Groups(['expense_report:read'])]
    private ?DemandeEscalade $escalationRequest = null;

    #[ORM\Column(name: 'rejection_reason', type: 'text', nullable: true)]
    #[Groups(['expense_report:read'])]
    private ?string $rejectionReason = null;

    #[ORM\ManyToOne(targetEntity: EcritureComptable::class)]
    #[ORM\JoinColumn(name: 'ledger_entry_id', nullable: true)]
    #[Groups(['expense_report:read'])]
    private ?EcritureComptable $ledgerEntry = null;

    #[ORM\Column(name: 'submitted_at', type: 'datetime_immutable', nullable: true)]
    #[Groups(['expense_report:read'])]
    private ?\DateTimeImmutable $submittedAt = null;

    #[ORM\Column(name: 'approved_at', type: 'datetime_immutable', nullable: true)]
    #[Groups(['expense_report:read'])]
    private ?\DateTimeImmutable $approvedAt = null;

    #[ORM\Column(name: 'reimbursed_at', type: 'datetime_immutable', nullable: true)]
    #[Groups(['expense_report:read'])]
    private ?\DateTimeImmutable $reimbursedAt = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    #[Groups(['expense_report:read'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(name: 'created_by_id', nullable: true)]
    #[Groups(['expense_report:read'])]
    private ?Utilisateur $createdBy = null;

    /** @var Collection<int, ExpenseLine> */
    #[ORM\OneToMany(targetEntity: ExpenseLine::class, mappedBy: 'expenseReport', cascade: ['persist'], orphanRemoval: true)]
    #[Groups(['expense_report:read'])]
    private Collection $lines;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->createdAt = new \DateTimeImmutable();
        $this->lines = new ArrayCollection();
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

    public function getBusinessProfile(): ?ProfilExploitant
    {
        return $this->businessProfile;
    }

    public function setBusinessProfile(?ProfilExploitant $businessProfile): self
    {
        $this->businessProfile = $businessProfile;

        return $this;
    }

    public function getEmployee(): ?Employe
    {
        return $this->employee;
    }

    public function setEmployee(?Employe $employee): self
    {
        $this->employee = $employee;

        return $this;
    }

    public function getStatus(): ExpenseReportStatus
    {
        return $this->status;
    }

    public function setStatus(ExpenseReportStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getTotalAmount(): string
    {
        return $this->totalAmount;
    }

    public function setTotalAmount(string $totalAmount): self
    {
        $this->totalAmount = $totalAmount;

        return $this;
    }

    public function getEscalationRequest(): ?DemandeEscalade
    {
        return $this->escalationRequest;
    }

    public function setEscalationRequest(?DemandeEscalade $escalationRequest): self
    {
        $this->escalationRequest = $escalationRequest;

        return $this;
    }

    public function getRejectionReason(): ?string
    {
        return $this->rejectionReason;
    }

    public function setRejectionReason(?string $rejectionReason): self
    {
        $this->rejectionReason = $rejectionReason;

        return $this;
    }

    public function getLedgerEntry(): ?EcritureComptable
    {
        return $this->ledgerEntry;
    }

    public function setLedgerEntry(?EcritureComptable $ledgerEntry): self
    {
        $this->ledgerEntry = $ledgerEntry;

        return $this;
    }

    public function getSubmittedAt(): ?\DateTimeImmutable
    {
        return $this->submittedAt;
    }

    public function setSubmittedAt(?\DateTimeImmutable $submittedAt): self
    {
        $this->submittedAt = $submittedAt;

        return $this;
    }

    public function getApprovedAt(): ?\DateTimeImmutable
    {
        return $this->approvedAt;
    }

    public function setApprovedAt(?\DateTimeImmutable $approvedAt): self
    {
        $this->approvedAt = $approvedAt;

        return $this;
    }

    public function getReimbursedAt(): ?\DateTimeImmutable
    {
        return $this->reimbursedAt;
    }

    public function setReimbursedAt(?\DateTimeImmutable $reimbursedAt): self
    {
        $this->reimbursedAt = $reimbursedAt;

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

    /** @return Collection<int, ExpenseLine> */
    public function getLines(): Collection
    {
        return $this->lines;
    }

    public function addLine(ExpenseLine $line): self
    {
        if (!$this->lines->contains($line)) {
            $this->lines->add($line);
            $line->setExpenseReport($this);
        }

        return $this;
    }
}
