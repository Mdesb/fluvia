<?php

declare(strict_types=1);

namespace App\Finance\ExpenseReport\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use App\Compta\Entity\EcritureComptable;
use App\Compta\Entity\MoyenPaiement;
use App\Finance\ExpenseReport\State\ReimburseExpenseReportProcessor;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Remboursement (déclaratif) d'une `ExpenseReport` (RG-EXP-05, §0.6 du plan) : **un seul par note**
 * (contrainte `UNIQUE(expense_report_id)`, remboursement partiel non retenu v1), montant devant égaler
 * exactement `ExpenseReport.totalAmount` (validé applicativement, `ReimburseExpenseReportHandler`).
 * Précondition mécanique : la note doit déjà être déversée en comptabilité (`ledgerEntry !== null` sur
 * la note) — un remboursement ne peut pas se lettrer contre une ligne 421 qui n'existe pas encore.
 */
#[ORM\Entity]
#[ORM\Table(name: 'finance_expense_reimbursement')]
#[ORM\UniqueConstraint(name: 'uniq_expense_reimbursement_report', columns: ['expense_report_id'])]
#[ApiResource(
    shortName: 'Reimbursement',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'finance.read') or is_granted('PERM', 'finance.expense_report_read_own') or is_granted('PERM', 'finance.expense_report_post_to_ledger') or is_granted('PERM', 'finance.manage')"),
        new Get(security: "is_granted('PERM', 'finance.read') or is_granted('PERM', 'finance.expense_report_read_own') or is_granted('PERM', 'finance.expense_report_post_to_ledger') or is_granted('PERM', 'finance.manage')"),
        new Post(
            uriTemplate: '/finance/expense-reports/{id}/reimbursements',
            // `id` désigne la note de frais parente, pas une propriété de `Reimbursement` : sans cette
            // déclaration API Platform ne sait pas résoudre la variable (même patron que
            // `App\Support\Entity\MessageTicket`).
            uriVariables: ['id' => new Link(fromClass: ExpenseReport::class, identifiers: ['id'])],
            // Sans `read: false`, API Platform tenterait de résoudre `{id}` comme l'identifiant de la
            // ressource `Reimbursement` elle-même (qui n'existe pas encore) : un POST crée, il ne
            // relit pas — même correctif que `MessageTicketProcessor`.
            read: false,
            security: "is_granted('PERM', 'finance.expense_report_post_to_ledger')",
            processor: ReimburseExpenseReportProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['reimbursement:read']],
    denormalizationContext: ['groups' => ['reimbursement:write']],
)]
#[ApiFilter(SearchFilter::class, properties: ['expenseReport' => 'exact'])]
class Reimbursement
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['reimbursement:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: ExpenseReport::class)]
    #[ORM\JoinColumn(name: 'expense_report_id', nullable: false)]
    #[Groups(['reimbursement:read', 'expense_report:read'])]
    private ?ExpenseReport $expenseReport = null;

    #[ORM\Column(type: 'date_immutable')]
    #[Groups(['reimbursement:read', 'reimbursement:write'])]
    private ?\DateTimeImmutable $date = null;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    #[Groups(['reimbursement:read', 'reimbursement:write'])]
    private string $amount = '0.00';

    #[ORM\ManyToOne(targetEntity: MoyenPaiement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['reimbursement:read', 'reimbursement:write'])]
    private ?MoyenPaiement $method = null;

    #[ORM\Column(length: 64, nullable: true)]
    #[Groups(['reimbursement:read', 'reimbursement:write'])]
    private ?string $reference = null;

    #[ORM\ManyToOne(targetEntity: EcritureComptable::class)]
    #[ORM\JoinColumn(name: 'ledger_entry_id', nullable: false)]
    #[Groups(['reimbursement:read'])]
    private ?EcritureComptable $ledgerEntry = null;

    #[ORM\Column(name: 'reconciliation_code', length: 36, nullable: true)]
    #[Groups(['reimbursement:read'])]
    private ?string $reconciliationCode = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    #[Groups(['reimbursement:read'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(name: 'created_by_id', nullable: true)]
    #[Groups(['reimbursement:read'])]
    private ?Utilisateur $createdBy = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->createdAt = new \DateTimeImmutable();
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

    public function getDate(): ?\DateTimeImmutable
    {
        return $this->date;
    }

    public function setDate(?\DateTimeImmutable $date): self
    {
        $this->date = $date;

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

    public function getMethod(): ?MoyenPaiement
    {
        return $this->method;
    }

    public function setMethod(?MoyenPaiement $method): self
    {
        $this->method = $method;

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

    public function getLedgerEntry(): ?EcritureComptable
    {
        return $this->ledgerEntry;
    }

    public function setLedgerEntry(?EcritureComptable $ledgerEntry): self
    {
        $this->ledgerEntry = $ledgerEntry;

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
}
