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
use App\Compta\Entity\TauxTva;
use App\Finance\SupplierInvoice\State\SupplierInvoiceLineProcessor;
use App\Stock\Entity\ArticleStock;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Ligne d'une facture fournisseur. Créée/éditée **séparément** de la facture (même patron que
 * `LigneCommandeAchat`/`LigneReceptionAchat` côté Stock, §2 du plan) — aucune modification possible une
 * fois la facture scellée (`status != draft`, RG-SINV-05, gardé par `SupplierInvoiceLineProcessor`).
 * `amountExclTax`/`vatAmount`/`amountInclTax` sont **toujours recalculés serveur**, jamais fournis par
 * le client.
 */
#[ORM\Entity]
#[ORM\Table(name: 'finance_supplier_invoice_line')]
#[ORM\Index(columns: ['supplier_invoice_id'], name: 'idx_supplier_invoice_line_invoice')]
#[ApiResource(
    shortName: 'SupplierInvoiceLine',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'finance.read')"),
        new Get(security: "is_granted('PERM', 'finance.read')"),
        new Post(
            security: "is_granted('PERM', 'finance.supplier_invoice_create')",
            processor: SupplierInvoiceLineProcessor::class,
        ),
        new Patch(
            security: "is_granted('PERM', 'finance.supplier_invoice_create')",
            processor: SupplierInvoiceLineProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['supplier_invoice_line:read']],
    denormalizationContext: ['groups' => ['supplier_invoice_line:write']],
)]
#[ApiFilter(SearchFilter::class, properties: ['supplierInvoice' => 'exact'])]
class SupplierInvoiceLine
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['supplier_invoice_line:read', 'supplier_invoice:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: SupplierInvoice::class, inversedBy: 'lines')]
    #[ORM\JoinColumn(name: 'supplier_invoice_id', nullable: false)]
    #[Assert\NotNull]
    #[Groups(['supplier_invoice_line:read', 'supplier_invoice_line:write'])]
    private ?SupplierInvoice $supplierInvoice = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Groups(['supplier_invoice_line:read', 'supplier_invoice_line:write', 'supplier_invoice:read'])]
    private string $description = '';

    #[ORM\ManyToOne(targetEntity: ArticleStock::class)]
    #[ORM\JoinColumn(name: 'stock_article_id', nullable: true)]
    #[Groups(['supplier_invoice_line:read', 'supplier_invoice_line:write', 'supplier_invoice:read'])]
    private ?ArticleStock $stockArticle = null;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 3)]
    #[Assert\Positive]
    #[Groups(['supplier_invoice_line:read', 'supplier_invoice_line:write', 'supplier_invoice:read'])]
    private string $quantity = '0.000';

    #[ORM\Column(name: 'unit_price_excl_tax', type: 'decimal', precision: 12, scale: 4)]
    #[Assert\GreaterThanOrEqual(0)]
    #[Groups(['supplier_invoice_line:read', 'supplier_invoice_line:write', 'supplier_invoice:read'])]
    private string $unitPriceExclTax = '0.0000';

    #[ORM\ManyToOne(targetEntity: TauxTva::class)]
    #[ORM\JoinColumn(name: 'vat_rate_id', nullable: false)]
    #[Assert\NotNull]
    #[Groups(['supplier_invoice_line:read', 'supplier_invoice_line:write', 'supplier_invoice:read'])]
    private ?TauxTva $vatRate = null;

    #[ORM\Column(name: 'expense_nature_code', length: 64)]
    #[Assert\NotBlank]
    #[Groups(['supplier_invoice_line:read', 'supplier_invoice_line:write', 'supplier_invoice:read'])]
    private string $expenseNatureCode = '';

    #[ORM\Column(name: 'amount_excl_tax', type: 'decimal', precision: 12, scale: 2)]
    #[Groups(['supplier_invoice_line:read', 'supplier_invoice:read'])]
    private string $amountExclTax = '0.00';

    #[ORM\Column(name: 'vat_amount', type: 'decimal', precision: 12, scale: 2)]
    #[Groups(['supplier_invoice_line:read', 'supplier_invoice:read'])]
    private string $vatAmount = '0.00';

    #[ORM\Column(name: 'amount_incl_tax', type: 'decimal', precision: 12, scale: 2)]
    #[Groups(['supplier_invoice_line:read', 'supplier_invoice:read'])]
    private string $amountInclTax = '0.00';

    public function __construct()
    {
        $this->id = Uuid::v4();
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

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function getStockArticle(): ?ArticleStock
    {
        return $this->stockArticle;
    }

    public function setStockArticle(?ArticleStock $stockArticle): self
    {
        $this->stockArticle = $stockArticle;

        return $this;
    }

    public function getQuantity(): string
    {
        return $this->quantity;
    }

    public function setQuantity(string $quantity): self
    {
        $this->quantity = $quantity;

        return $this;
    }

    public function getUnitPriceExclTax(): string
    {
        return $this->unitPriceExclTax;
    }

    public function setUnitPriceExclTax(string $unitPriceExclTax): self
    {
        $this->unitPriceExclTax = $unitPriceExclTax;

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

    public function getExpenseNatureCode(): string
    {
        return $this->expenseNatureCode;
    }

    public function setExpenseNatureCode(string $expenseNatureCode): self
    {
        $this->expenseNatureCode = $expenseNatureCode;

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

    public function getAmountInclTax(): string
    {
        return $this->amountInclTax;
    }

    /**
     * Recalcule les totaux serveur (RG-SINV — jamais fournis par le client) : `amountExclTax = quantity
     * x unitPriceExclTax`, `vatAmount` au taux réel de la ligne (RG-M6-05, pas de taux moyen).
     */
    public function recalculer(): self
    {
        $ht = ((float) $this->quantity) * ((float) $this->unitPriceExclTax);
        $tauxPourcent = $this->vatRate instanceof TauxTva ? (float) $this->vatRate->getTaux() : 0.0;
        $tva = $ht * $tauxPourcent / 100;
        $ttc = $ht + $tva;

        $this->amountExclTax = number_format(round($ht, 2), 2, '.', '');
        $this->vatAmount = number_format(round($tva, 2), 2, '.', '');
        $this->amountInclTax = number_format(round($ttc, 2), 2, '.', '');

        return $this;
    }
}
