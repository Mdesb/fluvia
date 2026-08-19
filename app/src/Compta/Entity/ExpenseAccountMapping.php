<?php

declare(strict_types=1);

namespace App\Compta\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Compta\State\ExpenseAccountMappingProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Mapping charges (US-L4-12, RG-M6-12, nouveau — nom anglais) : associe, par `ProfilExploitant`, une
 * nature de charge libre et paramétrable (`expenseNatureCode`, liste **ouverte**, jamais un enum PHP
 * fermé, RG-M6-12) à un compte de charge et un taux de TVA déductible. Symétrique de
 * `MappingComptable` côté produits — un mapping incomplet ou inactif bloque la génération d'écriture
 * pour la nature concernée, sans bloquer la saisie du document appelant (`ExpenseAccountMappingGuard`,
 * même patron que `MappingComptableGuard`).
 */
#[ORM\Entity]
#[ORM\Table(name: 'compta_expense_account_mapping')]
#[ORM\UniqueConstraint(name: 'uniq_expense_mapping_profil_nature', columns: ['business_profile_id', 'expense_nature_code'])]
#[ApiResource(
    shortName: 'ExpenseAccountMapping',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'compta.lire')"),
        new Get(security: "is_granted('PERM', 'compta.lire')"),
        new Post(security: "is_granted('PERM', 'compta.gerer')", processor: ExpenseAccountMappingProcessor::class),
        new Patch(security: "is_granted('PERM', 'compta.gerer')", processor: ExpenseAccountMappingProcessor::class),
    ],
    normalizationContext: ['groups' => ['expense_mapping:read']],
    denormalizationContext: ['groups' => ['expense_mapping:write']],
)]
#[ApiFilter(SearchFilter::class, properties: ['businessProfile' => 'exact', 'expenseNatureCode' => 'exact', 'active' => 'exact'])]
class ExpenseAccountMapping
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['expense_mapping:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: ProfilExploitant::class)]
    #[ORM\JoinColumn(name: 'business_profile_id', nullable: false)]
    #[Assert\NotNull]
    #[Groups(['expense_mapping:read', 'expense_mapping:write'])]
    private ?ProfilExploitant $businessProfile = null;

    /** Nature de charge, chaîne libre paramétrable (ex. `default_supplier`, `travel`, `lodging`, `meals`, `supplies`, `other`) — liste ouverte, RG-M6-12. */
    #[ORM\Column(length: 64)]
    #[Assert\NotBlank]
    #[Groups(['expense_mapping:read', 'expense_mapping:write'])]
    private string $expenseNatureCode = '';

    #[ORM\ManyToOne(targetEntity: CompteComptable::class)]
    #[ORM\JoinColumn(name: 'expense_account_id', nullable: false)]
    #[Assert\NotNull]
    #[Groups(['expense_mapping:read', 'expense_mapping:write'])]
    private ?CompteComptable $expenseAccount = null;

    #[ORM\ManyToOne(targetEntity: TauxTva::class)]
    #[ORM\JoinColumn(name: 'deductible_vat_rate_id', nullable: false)]
    #[Assert\NotNull]
    #[Groups(['expense_mapping:read', 'expense_mapping:write'])]
    private ?TauxTva $deductibleVatRate = null;

    #[ORM\Column(options: ['default' => true])]
    #[Groups(['expense_mapping:read', 'expense_mapping:write'])]
    private bool $active = true;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
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

    /** Rattachement multi-entités (cloisonnement) : via le profil exploitant, même patron que le reste du module. */
    public function getEtablissement(): ?\App\Organisation\Entity\Etablissement
    {
        return $this->businessProfile?->getEtablissementPrincipal();
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

    public function getExpenseAccount(): ?CompteComptable
    {
        return $this->expenseAccount;
    }

    public function setExpenseAccount(?CompteComptable $expenseAccount): self
    {
        $this->expenseAccount = $expenseAccount;

        return $this;
    }

    public function getDeductibleVatRate(): ?TauxTva
    {
        return $this->deductibleVatRate;
    }

    public function setDeductibleVatRate(?TauxTva $deductibleVatRate): self
    {
        $this->deductibleVatRate = $deductibleVatRate;

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

    /** Même garde que `MappingComptable::estValide()` (CA-3). */
    public function estValide(): bool
    {
        return $this->active
            && $this->expenseAccount !== null && $this->expenseAccount->isActif()
            && $this->deductibleVatRate !== null && $this->deductibleVatRate->isActif();
    }
}
