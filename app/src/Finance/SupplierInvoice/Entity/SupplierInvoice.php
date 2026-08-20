<?php

declare(strict_types=1);

namespace App\Finance\SupplierInvoice\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Compta\Entity\EcritureComptable;
use App\Compta\Entity\ProfilExploitant;
use App\Finance\SupplierInvoice\Enum\SupplierInvoiceNature;
use App\Finance\SupplierInvoice\Enum\SupplierInvoiceSource;
use App\Finance\SupplierInvoice\Enum\SupplierInvoiceStatus;
use App\Finance\SupplierInvoice\State\ApproveSupplierInvoiceProcessor;
use App\Finance\SupplierInvoice\State\CancelSupplierInvoiceProcessor;
use App\Finance\SupplierInvoice\State\CreditNoteSupplierInvoiceProcessor;
use App\Finance\SupplierInvoice\State\DisputeSupplierInvoiceProcessor;
use App\Finance\SupplierInvoice\State\ExtractSupplierInvoiceProcessor;
use App\Finance\SupplierInvoice\State\ReconciliationGapProvider;
use App\Finance\SupplierInvoice\State\ResolveDisputeSupplierInvoiceProcessor;
use App\Finance\SupplierInvoice\State\SupplierInvoiceProcessor;
use App\Ocr\Entity\ExtractionAttempt;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use App\Stock\Entity\CommandeAchat;
use App\Stock\Entity\Fournisseur;
use App\Stock\Entity\ReceptionAchat;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Facture fournisseur (lot FIN-2, US-SINV-01 à 09) : `establishment` **direct** (comme
 * `CommandeAchat`/`ReceptionAchat`, pas seulement via `businessProfile`) — c'est l'ancre de périmètre
 * de toute la brique et la source du tenant d'événement (D6, plan §0.2/§0.6). Aucune écriture n'est
 * générée à la création : la validation (`approve`, RG-SINV-05) est le fait générateur comptable.
 */
#[ORM\Entity]
#[ORM\Table(name: 'finance_supplier_invoice')]
#[ORM\Index(columns: ['establishment_id', 'status'], name: 'idx_supplier_invoice_establishment_status')]
#[ORM\Index(columns: ['supplier_id'], name: 'idx_supplier_invoice_supplier')]
#[ApiResource(
    shortName: 'SupplierInvoice',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'finance.read')"),
        new Get(security: "is_granted('PERM', 'finance.read')"),
        new Post(
            security: "is_granted('PERM', 'finance.supplier_invoice_create')",
            processor: SupplierInvoiceProcessor::class,
        ),
        new Patch(
            security: "is_granted('PERM', 'finance.supplier_invoice_create')",
            processor: SupplierInvoiceProcessor::class,
        ),
        new Post(
            uriTemplate: '/finance/supplier-invoices/extract',
            read: false,
            input: false,
            output: false,
            security: "is_granted('PERM', 'finance.supplier_invoice_create')",
            processor: ExtractSupplierInvoiceProcessor::class,
        ),
        new Post(
            uriTemplate: '/finance/supplier-invoices/{id}/approve',
            read: true,
            input: false,
            security: "is_granted('PERM', 'finance.supplier_invoice_approve')",
            processor: ApproveSupplierInvoiceProcessor::class,
        ),
        new Post(
            uriTemplate: '/finance/supplier-invoices/{id}/cancel',
            read: true,
            input: false,
            security: "is_granted('PERM', 'finance.supplier_invoice_create')",
            processor: CancelSupplierInvoiceProcessor::class,
        ),
        new Post(
            uriTemplate: '/finance/supplier-invoices/{id}/dispute',
            read: true,
            input: false,
            security: "is_granted('PERM', 'finance.supplier_invoice_dispute')",
            processor: DisputeSupplierInvoiceProcessor::class,
        ),
        new Post(
            uriTemplate: '/finance/supplier-invoices/{id}/resolve-dispute',
            read: true,
            input: false,
            security: "is_granted('PERM', 'finance.supplier_invoice_dispute')",
            processor: ResolveDisputeSupplierInvoiceProcessor::class,
        ),
        new Post(
            uriTemplate: '/finance/supplier-invoices/{id}/credit-note',
            read: true,
            input: false,
            security: "is_granted('PERM', 'finance.supplier_invoice_approve')",
            processor: CreditNoteSupplierInvoiceProcessor::class,
        ),
        new GetCollection(
            uriTemplate: '/finance/supplier-invoices/{id}/reconciliation',
            security: "is_granted('PERM', 'finance.read')",
            provider: ReconciliationGapProvider::class,
        ),
    ],
    normalizationContext: ['groups' => ['supplier_invoice:read']],
    denormalizationContext: ['groups' => ['supplier_invoice:write']],
)]
#[ApiFilter(SearchFilter::class, properties: ['status' => 'exact', 'supplier' => 'exact', 'businessProfile' => 'exact', 'nature' => 'exact'])]
class SupplierInvoice
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['supplier_invoice:read', 'supplier_invoice_line:read', 'supplier_payment:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(name: 'establishment_id', nullable: false)]
    #[Assert\NotNull]
    #[Groups(['supplier_invoice:read', 'supplier_invoice:write'])]
    private ?Etablissement $establishment = null;

    #[ORM\ManyToOne(targetEntity: ProfilExploitant::class)]
    #[ORM\JoinColumn(name: 'business_profile_id', nullable: false)]
    #[Assert\NotNull]
    #[Groups(['supplier_invoice:read', 'supplier_invoice:write'])]
    private ?ProfilExploitant $businessProfile = null;

    #[ORM\ManyToOne(targetEntity: Fournisseur::class)]
    #[ORM\JoinColumn(name: 'supplier_id', nullable: false)]
    #[Assert\NotNull]
    #[Groups(['supplier_invoice:read', 'supplier_invoice:write'])]
    private ?Fournisseur $supplier = null;

    #[ORM\Column(length: 12, enumType: SupplierInvoiceNature::class, options: ['default' => 'invoice'])]
    #[Groups(['supplier_invoice:read'])]
    private SupplierInvoiceNature $nature = SupplierInvoiceNature::Invoice;

    #[ORM\Column(name: 'supplier_invoice_number', length: 64)]
    #[Assert\NotBlank]
    #[Groups(['supplier_invoice:read', 'supplier_invoice:write'])]
    private string $supplierInvoiceNumber = '';

    #[ORM\Column(name: 'invoice_date', type: 'date_immutable')]
    #[Assert\NotNull]
    #[Groups(['supplier_invoice:read', 'supplier_invoice:write'])]
    private ?\DateTimeImmutable $invoiceDate = null;

    #[ORM\Column(name: 'due_date', type: 'date_immutable')]
    #[Assert\NotNull]
    #[Groups(['supplier_invoice:read', 'supplier_invoice:write'])]
    private ?\DateTimeImmutable $dueDate = null;

    #[ORM\Column(length: 16, enumType: SupplierInvoiceStatus::class, options: ['default' => 'draft'])]
    #[Groups(['supplier_invoice:read'])]
    private SupplierInvoiceStatus $status = SupplierInvoiceStatus::Draft;

    #[ORM\ManyToOne(targetEntity: CommandeAchat::class)]
    #[ORM\JoinColumn(name: 'purchase_order_id', nullable: true)]
    #[Groups(['supplier_invoice:read', 'supplier_invoice:write'])]
    private ?CommandeAchat $purchaseOrder = null;

    #[ORM\ManyToOne(targetEntity: ReceptionAchat::class)]
    #[ORM\JoinColumn(name: 'goods_receipt_id', nullable: true)]
    #[Groups(['supplier_invoice:read', 'supplier_invoice:write'])]
    private ?ReceptionAchat $goodsReceipt = null;

    #[ORM\Column(name: 'amount_excl_tax', type: 'decimal', precision: 12, scale: 2, options: ['default' => '0.00'])]
    #[Groups(['supplier_invoice:read'])]
    private string $amountExclTax = '0.00';

    #[ORM\Column(name: 'amount_incl_tax', type: 'decimal', precision: 12, scale: 2, options: ['default' => '0.00'])]
    #[Groups(['supplier_invoice:read'])]
    private string $amountInclTax = '0.00';

    #[ORM\Column(name: 'attachment_file_name', length: 255, nullable: true)]
    #[Groups(['supplier_invoice:read', 'supplier_invoice:write'])]
    private ?string $attachmentFileName = null;

    #[ORM\Column(name: 'attachment_mime_type', length: 100, nullable: true)]
    #[Groups(['supplier_invoice:read', 'supplier_invoice:write'])]
    private ?string $attachmentMimeType = null;

    #[ORM\Column(name: 'attachment_size', type: 'integer', nullable: true)]
    #[Groups(['supplier_invoice:read', 'supplier_invoice:write'])]
    private ?int $attachmentSize = null;

    #[ORM\Column(name: 'attachment_url', length: 500, nullable: true)]
    #[Groups(['supplier_invoice:read', 'supplier_invoice:write'])]
    private ?string $attachmentUrl = null;

    #[ORM\ManyToOne(targetEntity: ExtractionAttempt::class)]
    #[ORM\JoinColumn(name: 'ocr_extraction_id', nullable: true)]
    #[Groups(['supplier_invoice:read', 'supplier_invoice:write'])]
    private ?ExtractionAttempt $ocrExtraction = null;

    #[ORM\Column(length: 8, enumType: SupplierInvoiceSource::class, options: ['default' => 'manual'])]
    #[Groups(['supplier_invoice:read'])]
    private SupplierInvoiceSource $source = SupplierInvoiceSource::Manual;

    #[ORM\ManyToOne(targetEntity: EcritureComptable::class)]
    #[ORM\JoinColumn(name: 'ledger_entry_id', nullable: true)]
    #[Groups(['supplier_invoice:read'])]
    private ?EcritureComptable $ledgerEntry = null;

    #[ORM\Column(name: 'dispute_reason', type: 'text', nullable: true)]
    #[Groups(['supplier_invoice:read'])]
    private ?string $disputeReason = null;

    #[ORM\Column(name: 'dispute_resolution_reason', type: 'text', nullable: true)]
    #[Groups(['supplier_invoice:read'])]
    private ?string $disputeResolutionReason = null;

    /** Pointe vers la facture d'origine (renommé depuis `correctedBy` de la spec, §0.9 du plan). */
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'corrects_invoice_id', nullable: true)]
    #[Groups(['supplier_invoice:read'])]
    private ?self $correctsInvoice = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    #[Groups(['supplier_invoice:read'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(name: 'created_by_id', nullable: true)]
    #[Groups(['supplier_invoice:read'])]
    private ?Utilisateur $createdBy = null;

    /** @var Collection<int, SupplierInvoiceLine> */
    #[ORM\OneToMany(targetEntity: SupplierInvoiceLine::class, mappedBy: 'supplierInvoice', cascade: ['persist'], orphanRemoval: true)]
    #[Groups(['supplier_invoice:read'])]
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

    public function getEtablissement(): ?Etablissement
    {
        return $this->establishment;
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

    public function getSupplier(): ?Fournisseur
    {
        return $this->supplier;
    }

    public function setSupplier(?Fournisseur $supplier): self
    {
        $this->supplier = $supplier;

        return $this;
    }

    public function getNature(): SupplierInvoiceNature
    {
        return $this->nature;
    }

    public function setNature(SupplierInvoiceNature $nature): self
    {
        $this->nature = $nature;

        return $this;
    }

    public function getSupplierInvoiceNumber(): string
    {
        return $this->supplierInvoiceNumber;
    }

    public function setSupplierInvoiceNumber(string $supplierInvoiceNumber): self
    {
        $this->supplierInvoiceNumber = $supplierInvoiceNumber;

        return $this;
    }

    public function getInvoiceDate(): ?\DateTimeImmutable
    {
        return $this->invoiceDate;
    }

    public function setInvoiceDate(?\DateTimeImmutable $invoiceDate): self
    {
        $this->invoiceDate = $invoiceDate;

        return $this;
    }

    public function getDueDate(): ?\DateTimeImmutable
    {
        return $this->dueDate;
    }

    public function setDueDate(?\DateTimeImmutable $dueDate): self
    {
        $this->dueDate = $dueDate;

        return $this;
    }

    public function getStatus(): SupplierInvoiceStatus
    {
        return $this->status;
    }

    public function setStatus(SupplierInvoiceStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getPurchaseOrder(): ?CommandeAchat
    {
        return $this->purchaseOrder;
    }

    public function setPurchaseOrder(?CommandeAchat $purchaseOrder): self
    {
        $this->purchaseOrder = $purchaseOrder;

        return $this;
    }

    public function getGoodsReceipt(): ?ReceptionAchat
    {
        return $this->goodsReceipt;
    }

    public function setGoodsReceipt(?ReceptionAchat $goodsReceipt): self
    {
        $this->goodsReceipt = $goodsReceipt;

        return $this;
    }

    public function getAmountExclTax(): string
    {
        return $this->amountExclTax;
    }

    public function setAmountExclTax(string $amountExclTax): self
    {
        $this->amountExclTax = $amountExclTax;

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

    public function getAttachmentFileName(): ?string
    {
        return $this->attachmentFileName;
    }

    public function setAttachmentFileName(?string $attachmentFileName): self
    {
        $this->attachmentFileName = $attachmentFileName;

        return $this;
    }

    public function getAttachmentMimeType(): ?string
    {
        return $this->attachmentMimeType;
    }

    public function setAttachmentMimeType(?string $attachmentMimeType): self
    {
        $this->attachmentMimeType = $attachmentMimeType;

        return $this;
    }

    public function getAttachmentSize(): ?int
    {
        return $this->attachmentSize;
    }

    public function setAttachmentSize(?int $attachmentSize): self
    {
        $this->attachmentSize = $attachmentSize;

        return $this;
    }

    public function getAttachmentUrl(): ?string
    {
        return $this->attachmentUrl;
    }

    public function setAttachmentUrl(?string $attachmentUrl): self
    {
        $this->attachmentUrl = $attachmentUrl;

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

    public function getSource(): SupplierInvoiceSource
    {
        return $this->source;
    }

    public function setSource(SupplierInvoiceSource $source): self
    {
        $this->source = $source;

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

    public function getDisputeReason(): ?string
    {
        return $this->disputeReason;
    }

    public function setDisputeReason(?string $disputeReason): self
    {
        $this->disputeReason = $disputeReason;

        return $this;
    }

    public function getDisputeResolutionReason(): ?string
    {
        return $this->disputeResolutionReason;
    }

    public function setDisputeResolutionReason(?string $disputeResolutionReason): self
    {
        $this->disputeResolutionReason = $disputeResolutionReason;

        return $this;
    }

    public function getCorrectsInvoice(): ?self
    {
        return $this->correctsInvoice;
    }

    public function setCorrectsInvoice(?self $correctsInvoice): self
    {
        $this->correctsInvoice = $correctsInvoice;

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

    /** @return Collection<int, SupplierInvoiceLine> */
    public function getLines(): Collection
    {
        return $this->lines;
    }

    public function addLine(SupplierInvoiceLine $line): self
    {
        if (!$this->lines->contains($line)) {
            $this->lines->add($line);
            $line->setSupplierInvoice($this);
        }

        return $this;
    }
}
