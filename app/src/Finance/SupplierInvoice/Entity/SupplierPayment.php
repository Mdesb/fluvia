<?php

declare(strict_types=1);

namespace App\Finance\SupplierInvoice\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Compta\Entity\EcritureComptable;
use App\Compta\Entity\MoyenPaiement;
use App\Finance\SupplierInvoice\State\SupplierPaymentProcessor;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Règlement (total ou partiel) d'une facture fournisseur (RG-SINV-07). Chaque règlement génère sa
 * **propre** écriture (débit 401 / crédit 512, §0.8 du plan) — le lettrage groupé avec la ligne 401 de
 * la facture d'origine n'est déclenché que lorsque la somme des règlements égale exactement le montant
 * facturé (lettrage différé, RG-M6-14).
 */
#[ORM\Entity]
#[ORM\Table(name: 'finance_supplier_payment')]
#[ORM\Index(columns: ['supplier_invoice_id'], name: 'idx_supplier_payment_invoice')]
#[ApiResource(
    shortName: 'SupplierPayment',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'finance.read')"),
        new Get(security: "is_granted('PERM', 'finance.read')"),
        new Post(
            security: "is_granted('PERM', 'finance.supplier_invoice_pay')",
            processor: SupplierPaymentProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['supplier_payment:read']],
    denormalizationContext: ['groups' => ['supplier_payment:write']],
)]
#[ApiFilter(SearchFilter::class, properties: ['supplierInvoice' => 'exact'])]
class SupplierPayment
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['supplier_payment:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: SupplierInvoice::class)]
    #[ORM\JoinColumn(name: 'supplier_invoice_id', nullable: false)]
    #[Groups(['supplier_payment:read', 'supplier_payment:write'])]
    private ?SupplierInvoice $supplierInvoice = null;

    #[ORM\Column(type: 'date_immutable')]
    #[Groups(['supplier_payment:read', 'supplier_payment:write'])]
    private ?\DateTimeImmutable $date = null;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    #[Groups(['supplier_payment:read', 'supplier_payment:write'])]
    private string $amount = '0.00';

    #[ORM\ManyToOne(targetEntity: MoyenPaiement::class)]
    #[ORM\JoinColumn(name: 'payment_method_id', nullable: false)]
    #[Groups(['supplier_payment:read', 'supplier_payment:write'])]
    private ?MoyenPaiement $paymentMethod = null;

    #[ORM\Column(length: 64, nullable: true)]
    #[Groups(['supplier_payment:read', 'supplier_payment:write'])]
    private ?string $reference = null;

    #[ORM\ManyToOne(targetEntity: EcritureComptable::class)]
    #[ORM\JoinColumn(name: 'ledger_entry_id', nullable: false)]
    #[Groups(['supplier_payment:read'])]
    private ?EcritureComptable $ledgerEntry = null;

    #[ORM\Column(name: 'reconciliation_code', length: 36, nullable: true)]
    #[Groups(['supplier_payment:read'])]
    private ?string $reconciliationCode = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    #[Groups(['supplier_payment:read'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(name: 'created_by_id', nullable: true)]
    #[Groups(['supplier_payment:read'])]
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

    public function getSupplierInvoice(): ?SupplierInvoice
    {
        return $this->supplierInvoice;
    }

    public function setSupplierInvoice(?SupplierInvoice $supplierInvoice): self
    {
        $this->supplierInvoice = $supplierInvoice;

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

    public function getPaymentMethod(): ?MoyenPaiement
    {
        return $this->paymentMethod;
    }

    public function setPaymentMethod(?MoyenPaiement $paymentMethod): self
    {
        $this->paymentMethod = $paymentMethod;

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
