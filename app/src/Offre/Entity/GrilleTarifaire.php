<?php

declare(strict_types=1);

namespace App\Offre\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
use App\Offre\State\PriceGridProcessor;

/**
 * Case de grille tarifaire : un prix déterminé par le triplet produit × type de tarif × saison
 * (+ tranche de QF éventuelle) — RG-M1-01. Le triplet est unique. Un prix null vaut
 * « non commercialisé » (≠ gratuit) — CA-5. Toute modification de prix est historisée
 * (PrixHistorique, append-only) et non rétroactive (US-L1-03).
 */
#[ORM\Entity]
#[ORM\Table(name: 'off_grille_tarifaire')]
#[ORM\UniqueConstraint(name: 'uniq_grille_triplet', columns: ['produit_id', 'type_tarif_id', 'saison_id', 'tranche_qf_id'])]
#[ApiResource(
    shortName: 'GrilleTarifaire',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'offre.lire')"),
        new Get(security: "is_granted('PERM', 'offre.lire')"),
        new Post(security: "is_granted('PERM', 'offre.creer')"),
        new Patch(
            security: "is_granted('PERM', 'offre.modifier')",
            processor: PriceGridProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['grille:read']],
    denormalizationContext: ['groups' => ['grille:write']],
)]
#[ApiFilter(SearchFilter::class, properties: ['produit' => 'exact', 'typeTarif' => 'exact', 'saison' => 'exact'])]
class GrilleTarifaire
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['grille:read', 'produit:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Produit::class, inversedBy: 'grilles')]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['grille:read', 'grille:write'])]
    private ?Produit $produit = null;

    #[ORM\ManyToOne(targetEntity: TypeTarif::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['grille:read', 'grille:write', 'produit:read'])]
    private ?TypeTarif $typeTarif = null;

    #[ORM\ManyToOne(targetEntity: Saison::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['grille:read', 'grille:write', 'produit:read'])]
    private ?Saison $saison = null;

    /** Prix TTC. null = « non commercialisé » (≠ gratuit) — CA-5. */
    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    #[Assert\PositiveOrZero(message: 'Un prix doit être supérieur ou égal à zéro.')]
    #[Groups(['grille:read', 'grille:write', 'produit:read'])]
    private ?string $prix = null;

    #[ORM\ManyToOne(targetEntity: TrancheQuotientFamilial::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['grille:read', 'grille:write', 'produit:read'])]
    private ?TrancheQuotientFamilial $trancheQf = null;

    /** @var Collection<int, PrixHistorique> */
    #[ORM\OneToMany(targetEntity: PrixHistorique::class, mappedBy: 'grille', cascade: ['persist'])]
    #[Groups(['grille:read'])]
    private Collection $historique;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->historique = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getProduit(): ?Produit
    {
        return $this->produit;
    }

    public function setProduit(?Produit $produit): self
    {
        $this->produit = $produit;

        return $this;
    }

    public function getTypeTarif(): ?TypeTarif
    {
        return $this->typeTarif;
    }

    public function setTypeTarif(?TypeTarif $typeTarif): self
    {
        $this->typeTarif = $typeTarif;

        return $this;
    }

    public function getSaison(): ?Saison
    {
        return $this->saison;
    }

    public function setSaison(?Saison $saison): self
    {
        $this->saison = $saison;

        return $this;
    }

    public function getPrix(): ?string
    {
        return $this->prix;
    }

    public function setPrix(?string $prix): self
    {
        $this->prix = $prix;

        return $this;
    }

    public function getTrancheQf(): ?TrancheQuotientFamilial
    {
        return $this->trancheQf;
    }

    public function setTrancheQf(?TrancheQuotientFamilial $trancheQf): self
    {
        $this->trancheQf = $trancheQf;

        return $this;
    }

    /** @return Collection<int, PrixHistorique> */
    public function getHistorique(): Collection
    {
        return $this->historique;
    }

    public function addHistorique(PrixHistorique $historique): self
    {
        if (!$this->historique->contains($historique)) {
            $this->historique->add($historique);
            $historique->setGrille($this);
        }

        return $this;
    }
}
