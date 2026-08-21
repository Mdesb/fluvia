<?php

declare(strict_types=1);

namespace App\Finance\Treasury\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Compta\Entity\CompteComptable;
use App\Finance\Treasury\State\BankAccountProcessor;
use App\Finance\Treasury\State\CashflowForecastProvider;
use App\Finance\Treasury\State\DiscrepancyDashboardProvider;
use App\Finance\Treasury\State\PaymentScheduleProvider;
use App\Finance\Treasury\State\TreasuryPositionProvider;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Compte bancaire de l'établissement (lot FIN-4, US-TRE-01, RG-TRE-01) — `establishment` **direct**
 * (même ancre que `SupplierInvoice`/`ExpenseReport`, §0.2 du plan), source du tenant d'événement (D6).
 *
 * IBAN chiffré au repos via le coffre **SEPA** réutilisé tel quel (`ChiffreurIbanInterface`, §0.3 du
 * plan) — aucun second mécanisme de chiffrement. `ibanCipher` ne porte **aucun** `#[Groups]` : il n'est
 * jamais sérialisé, quel que soit le contexte de normalisation (CA-1). Seul `ibanLast4` est lisible.
 * `ibanClear` est un champ **transitoire** (jamais mappé Doctrine), consommé uniquement en entrée par
 * `BankAccountProcessor`, jamais persisté en clair, jamais journalisé.
 *
 * Les quatre opérations calculées de la brique (position, échéancier, prévisionnel, tableau de bord des
 * écarts, §0.8/§2 du plan) sont déclarées ici plutôt que sur une entité dédiée : `BankAccount` est
 * l'ancre naturelle du module (même patron que `VerifierChaineEcritureProcessor`, attaché à
 * `EcritureComptable` bien que sa réponse ne soit pas une écriture) — chacune renvoie directement une
 * `JsonResponse` (le retour d'un provider qui est déjà une `Response` court-circuite la sérialisation
 * API Platform, patron déjà utilisé par `ReconciliationGapProvider`/`VerifierChaineEcritureProcessor`),
 * pas d'entité/DTO `#[ApiResource]` dédié.
 */
#[ORM\Entity]
#[ORM\Table(name: 'finance_treasury_bank_account')]
#[ORM\Index(columns: ['establishment_id'], name: 'idx_treasury_bank_account_establishment')]
#[ApiResource(
    shortName: 'BankAccount',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'finance.read')"),
        new Get(security: "is_granted('PERM', 'finance.read')"),
        new Post(
            security: "is_granted('PERM', 'finance.treasury_manage_account')",
            processor: BankAccountProcessor::class,
        ),
        new Patch(
            security: "is_granted('PERM', 'finance.treasury_manage_account')",
            processor: BankAccountProcessor::class,
        ),
        new GetCollection(
            uriTemplate: '/finance/treasury/position',
            paginationEnabled: false,
            security: "is_granted('PERM', 'finance.read')",
            provider: TreasuryPositionProvider::class,
        ),
        new GetCollection(
            uriTemplate: '/finance/treasury/payment-schedule',
            paginationEnabled: false,
            security: "is_granted('PERM', 'finance.read')",
            provider: PaymentScheduleProvider::class,
        ),
        new GetCollection(
            uriTemplate: '/finance/treasury/cashflow-forecast',
            paginationEnabled: false,
            security: "is_granted('PERM', 'finance.read')",
            provider: CashflowForecastProvider::class,
        ),
        new GetCollection(
            uriTemplate: '/finance/treasury/discrepancies',
            paginationEnabled: false,
            security: "is_granted('PERM', 'finance.read')",
            provider: DiscrepancyDashboardProvider::class,
        ),
    ],
    normalizationContext: ['groups' => ['bank_account:read']],
    denormalizationContext: ['groups' => ['bank_account:write']],
)]
#[ApiFilter(SearchFilter::class, properties: ['active' => 'exact'])]
class BankAccount
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['bank_account:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(name: 'establishment_id', nullable: false)]
    #[Assert\NotNull]
    #[Groups(['bank_account:read', 'bank_account:write'])]
    private ?Etablissement $establishment = null;

    /** Classe 512 par convention (non forcé en base — validé applicativement, §1 du plan). */
    #[ORM\ManyToOne(targetEntity: CompteComptable::class)]
    #[ORM\JoinColumn(name: 'ledger_account_id', nullable: true)]
    #[Groups(['bank_account:read', 'bank_account:write'])]
    private ?CompteComptable $ledgerAccount = null;

    /** Coffre IBAN réversible (`ChiffreurIbanInterface`, SEPA). Volontairement sans `#[Groups]` (CA-1). */
    #[ORM\Column(name: 'iban_cipher', type: 'text', nullable: true)]
    private ?string $ibanCipher = null;

    #[ORM\Column(name: 'iban_last4', length: 4, options: ['default' => ''])]
    #[Groups(['bank_account:read'])]
    private string $ibanLast4 = '';

    /** IBAN en clair — champ transitoire (jamais mappé Doctrine), consommé par `BankAccountProcessor`. */
    #[Groups(['bank_account:write'])]
    private string $ibanClear = '';

    #[ORM\Column(length: 11, options: ['default' => ''])]
    #[Groups(['bank_account:read', 'bank_account:write'])]
    private string $bic = '';

    #[ORM\Column(length: 140)]
    #[Assert\NotBlank]
    #[Groups(['bank_account:read', 'bank_account:write'])]
    private string $label = '';

    #[ORM\Column(name: 'opening_balance', type: 'decimal', precision: 12, scale: 2, options: ['default' => '0.00'])]
    #[Groups(['bank_account:read', 'bank_account:write'])]
    private string $openingBalance = '0.00';

    #[ORM\Column(name: 'opening_balance_date', type: 'date_immutable')]
    #[Assert\NotNull]
    #[Groups(['bank_account:read', 'bank_account:write'])]
    private ?\DateTimeImmutable $openingBalanceDate = null;

    #[ORM\Column(options: ['default' => true])]
    #[Groups(['bank_account:read', 'bank_account:write'])]
    private bool $active = true;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    #[Groups(['bank_account:read'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(name: 'created_by_id', nullable: true)]
    #[Groups(['bank_account:read'])]
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

    public function getEstablishment(): ?Etablissement
    {
        return $this->establishment;
    }

    public function setEstablishment(?Etablissement $establishment): self
    {
        $this->establishment = $establishment;

        return $this;
    }

    public function getLedgerAccount(): ?CompteComptable
    {
        return $this->ledgerAccount;
    }

    public function setLedgerAccount(?CompteComptable $ledgerAccount): self
    {
        $this->ledgerAccount = $ledgerAccount;

        return $this;
    }

    public function getIbanCipher(): ?string
    {
        return $this->ibanCipher;
    }

    public function setIbanCipher(?string $ibanCipher): self
    {
        $this->ibanCipher = $ibanCipher;

        return $this;
    }

    public function getIbanLast4(): string
    {
        return $this->ibanLast4;
    }

    public function setIbanLast4(string $ibanLast4): self
    {
        $this->ibanLast4 = $ibanLast4;

        return $this;
    }

    public function getIbanClear(): string
    {
        return $this->ibanClear;
    }

    public function setIbanClear(string $ibanClear): self
    {
        $this->ibanClear = $ibanClear;

        return $this;
    }

    public function getBic(): string
    {
        return $this->bic;
    }

    public function setBic(string $bic): self
    {
        $this->bic = $bic;

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

    public function getOpeningBalance(): string
    {
        return $this->openingBalance;
    }

    public function setOpeningBalance(string $openingBalance): self
    {
        $this->openingBalance = $openingBalance;

        return $this;
    }

    public function getOpeningBalanceDate(): ?\DateTimeImmutable
    {
        return $this->openingBalanceDate;
    }

    public function setOpeningBalanceDate(?\DateTimeImmutable $openingBalanceDate): self
    {
        $this->openingBalanceDate = $openingBalanceDate;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): self
    {
        $this->active = $active;

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
