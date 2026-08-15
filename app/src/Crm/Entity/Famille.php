<?php

declare(strict_types=1);

namespace App\Crm\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Crm\Enum\StatutFamille;
use App\Crm\State\AjouterBeneficiaireProcessor;
use App\Crm\State\FamilleEcritureProcessor;
use App\Organisation\Entity\Groupe;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Famille (Foyer) : payeur ≠ bénéficiaire, autorisations par bénéficiaire (RG-M4-02, US-L5-03).
 */
#[ORM\Entity]
#[ORM\Table(name: 'crm_famille')]
#[ApiResource(
    shortName: 'Famille',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'crm.lire')"),
        new Get(security: "is_granted('PERM', 'crm.lire')"),
        new Post(security: "is_granted('PERM', 'crm.famille_gerer')", processor: FamilleEcritureProcessor::class),
        new Patch(security: "is_granted('PERM', 'crm.famille_gerer')", processor: FamilleEcritureProcessor::class),
        new Post(
            uriTemplate: '/familles/{id}/beneficiaires',
            read: true,
            input: false,
            security: "is_granted('PERM', 'crm.famille_gerer')",
            processor: AjouterBeneficiaireProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['famille:read']],
    denormalizationContext: ['groups' => ['famille:write']],
)]
#[ApiFilter(SearchFilter::class, properties: ['libelle' => 'partial', 'payeurPrincipal' => 'exact'])]
class Famille
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['famille:read', 'beneficiaire:read', 'fusion:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Groupe::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Groupe $groupe = null;

    #[ORM\Column(length: 120, nullable: true)]
    #[Groups(['famille:read', 'famille:write'])]
    private ?string $libelle = null;

    #[ORM\ManyToOne(targetEntity: Client::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['famille:read', 'famille:write'])]
    private ?Client $payeurPrincipal = null;

    #[ORM\Column(length: 12, enumType: StatutFamille::class, options: ['default' => 'active'])]
    #[Groups(['famille:read'])]
    private StatutFamille $statut = StatutFamille::Active;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['famille:read'])]
    private \DateTimeImmutable $dateCreation;

    /** @var Collection<int, Beneficiaire> */
    #[ORM\OneToMany(targetEntity: Beneficiaire::class, mappedBy: 'famille', cascade: ['persist'])]
    #[Groups(['famille:read'])]
    private Collection $beneficiaires;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->dateCreation = new \DateTimeImmutable();
        $this->beneficiaires = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getGroupe(): ?Groupe
    {
        return $this->groupe;
    }

    public function setGroupe(?Groupe $groupe): self
    {
        $this->groupe = $groupe;

        return $this;
    }

    public function getLibelle(): ?string
    {
        return $this->libelle;
    }

    public function setLibelle(?string $libelle): self
    {
        $this->libelle = $libelle;

        return $this;
    }

    public function getPayeurPrincipal(): ?Client
    {
        return $this->payeurPrincipal;
    }

    public function setPayeurPrincipal(?Client $payeurPrincipal): self
    {
        $this->payeurPrincipal = $payeurPrincipal;

        return $this;
    }

    public function getStatut(): StatutFamille
    {
        return $this->statut;
    }

    public function setStatut(StatutFamille $statut): self
    {
        $this->statut = $statut;

        return $this;
    }

    public function getDateCreation(): \DateTimeImmutable
    {
        return $this->dateCreation;
    }

    /** @return Collection<int, Beneficiaire> */
    public function getBeneficiaires(): Collection
    {
        return $this->beneficiaires;
    }

    public function addBeneficiaire(Beneficiaire $beneficiaire): self
    {
        if (!$this->beneficiaires->contains($beneficiaire)) {
            $this->beneficiaires->add($beneficiaire);
            $beneficiaire->setFamille($this);
        }

        return $this;
    }

    /** Membres actifs (dateRetrait null) — US-L5-03. */
    public function membresActifs(): array
    {
        return array_values(array_filter(
            $this->beneficiaires->toArray(),
            static fn (Beneficiaire $b): bool => $b->getDateRetrait() === null,
        ));
    }
}
