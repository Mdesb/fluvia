<?php

declare(strict_types=1);

namespace App\Stock\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Organisation\Entity\Etablissement;
use App\Stock\Enum\PerimetreInventaire;
use App\Stock\Enum\StatutInventaire;
use App\Stock\State\ClorurerInventaireProcessor;
use App\Stock\State\LancerInventaireProcessor;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Inventaire physique (US-STOCK-11, RG-STOCK-12) : la quantité théorique de chaque ligne est figée
 * (snapshot) au lancement. Append-only après clôture.
 */
#[ORM\Entity]
#[ORM\Table(name: 'stk_inventaire')]
#[ApiResource(
    shortName: 'StockInventaire',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'stock.lire')"),
        new Get(security: "is_granted('PERM', 'stock.lire')"),
        new Post(security: "is_granted('PERM', 'stock.inventorier')", processor: LancerInventaireProcessor::class),
        new Post(
            uriTemplate: '/stock/inventaires/{id}/cloturer',
            read: true,
            input: false,
            security: "is_granted('PERM', 'stock.inventorier')",
            processor: ClorurerInventaireProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['inventaire:read']],
)]
class Inventaire
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['inventaire:read', 'ligne_inventaire:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['inventaire:read'])]
    private ?Etablissement $etablissement = null;

    #[ORM\Column(length: 10, enumType: PerimetreInventaire::class)]
    #[Groups(['inventaire:read'])]
    private PerimetreInventaire $perimetre = PerimetreInventaire::Tous;

    /** @var list<string>|null */
    #[ORM\Column(nullable: true)]
    #[Groups(['inventaire:read'])]
    private ?array $filtre = null;

    #[ORM\Column(length: 14, enumType: StatutInventaire::class)]
    #[Groups(['inventaire:read'])]
    private StatutInventaire $statut = StatutInventaire::EnCours;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['inventaire:read'])]
    private \DateTimeImmutable $dateLancement;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['inventaire:read'])]
    private ?\DateTimeImmutable $dateCloture = null;

    /** @var Collection<int, LigneInventaire> */
    #[ORM\OneToMany(targetEntity: LigneInventaire::class, mappedBy: 'inventaire', cascade: ['persist'])]
    #[Groups(['inventaire:read'])]
    private Collection $lignes;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->dateLancement = new \DateTimeImmutable();
        $this->lignes = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEtablissement(): ?Etablissement
    {
        return $this->etablissement;
    }

    public function setEtablissement(?Etablissement $etablissement): self
    {
        $this->etablissement = $etablissement;

        return $this;
    }

    public function getPerimetre(): PerimetreInventaire
    {
        return $this->perimetre;
    }

    public function setPerimetre(PerimetreInventaire $perimetre): self
    {
        $this->perimetre = $perimetre;

        return $this;
    }

    /** @return list<string>|null */
    public function getFiltre(): ?array
    {
        return $this->filtre;
    }

    /** @param list<string>|null $filtre */
    public function setFiltre(?array $filtre): self
    {
        $this->filtre = $filtre;

        return $this;
    }

    public function getStatut(): StatutInventaire
    {
        return $this->statut;
    }

    public function setStatut(StatutInventaire $statut): self
    {
        $this->statut = $statut;

        return $this;
    }

    public function getDateLancement(): \DateTimeImmutable
    {
        return $this->dateLancement;
    }

    public function setDateLancement(\DateTimeImmutable $dateLancement): self
    {
        $this->dateLancement = $dateLancement;

        return $this;
    }

    public function getDateCloture(): ?\DateTimeImmutable
    {
        return $this->dateCloture;
    }

    public function setDateCloture(?\DateTimeImmutable $dateCloture): self
    {
        $this->dateCloture = $dateCloture;

        return $this;
    }

    /** @return Collection<int, LigneInventaire> */
    public function getLignes(): Collection
    {
        return $this->lignes;
    }

    public function addLigne(LigneInventaire $ligne): self
    {
        if (!$this->lignes->contains($ligne)) {
            $this->lignes->add($ligne);
            $ligne->setInventaire($this);
        }

        return $this;
    }
}
