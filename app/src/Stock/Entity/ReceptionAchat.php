<?php

declare(strict_types=1);

namespace App\Stock\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Organisation\Entity\Etablissement;
use App\Stock\Enum\StatutReceptionAchat;
use App\Stock\State\ValiderReceptionAchatProcessor;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Réception d'achat (US-STOCK-04, RG-STOCK-05) : la validation crée les couches de coût (`LotStock`),
 * les mouvements `entree_achat` et met à jour `Stock.disponibilité` M1 (§3.1 du plan).
 */
#[ORM\Entity]
#[ORM\Table(name: 'stk_reception_achat')]
#[ApiResource(
    shortName: 'StockReceptionAchat',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'stock.lire')"),
        new Get(security: "is_granted('PERM', 'stock.lire')"),
        new Post(security: "is_granted('PERM', 'stock.receptionner') or is_granted('PERM', 'stock.gerer')"),
        new Patch(security: "is_granted('PERM', 'stock.receptionner') or is_granted('PERM', 'stock.gerer')"),
        new Post(
            uriTemplate: '/stock/receptions-achat/{id}/valider',
            read: true,
            input: false,
            security: "is_granted('PERM', 'stock.receptionner') or is_granted('PERM', 'stock.gerer')",
            processor: ValiderReceptionAchatProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['reception_achat:read']],
    denormalizationContext: ['groups' => ['reception_achat:write']],
)]
class ReceptionAchat
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['reception_achat:read', 'ligne_reception_achat:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: CommandeAchat::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['reception_achat:read', 'reception_achat:write'])]
    private ?CommandeAchat $commandeAchat = null;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['reception_achat:read', 'reception_achat:write'])]
    private ?Etablissement $etablissement = null;

    #[ORM\ManyToOne(targetEntity: Fournisseur::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['reception_achat:read', 'reception_achat:write'])]
    private ?Fournisseur $fournisseur = null;

    #[ORM\Column(type: 'date_immutable')]
    #[Assert\NotNull]
    #[Groups(['reception_achat:read', 'reception_achat:write'])]
    private ?\DateTimeImmutable $date = null;

    #[ORM\Column(length: 64)]
    #[Groups(['reception_achat:read', 'reception_achat:write'])]
    private string $numeroBonLivraison = '';

    #[ORM\Column(length: 10, enumType: StatutReceptionAchat::class)]
    #[Groups(['reception_achat:read'])]
    private StatutReceptionAchat $statut = StatutReceptionAchat::Brouillon;

    /** @var Collection<int, LigneReceptionAchat> */
    #[ORM\OneToMany(targetEntity: LigneReceptionAchat::class, mappedBy: 'reception', cascade: ['persist'], orphanRemoval: true)]
    #[Groups(['reception_achat:read'])]
    private Collection $lignes;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->date = new \DateTimeImmutable();
        $this->lignes = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCommandeAchat(): ?CommandeAchat
    {
        return $this->commandeAchat;
    }

    public function setCommandeAchat(?CommandeAchat $commandeAchat): self
    {
        $this->commandeAchat = $commandeAchat;

        return $this;
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

    public function getFournisseur(): ?Fournisseur
    {
        return $this->fournisseur;
    }

    public function setFournisseur(?Fournisseur $fournisseur): self
    {
        $this->fournisseur = $fournisseur;

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

    public function getNumeroBonLivraison(): string
    {
        return $this->numeroBonLivraison;
    }

    public function setNumeroBonLivraison(string $numeroBonLivraison): self
    {
        $this->numeroBonLivraison = $numeroBonLivraison;

        return $this;
    }

    public function getStatut(): StatutReceptionAchat
    {
        return $this->statut;
    }

    public function setStatut(StatutReceptionAchat $statut): self
    {
        $this->statut = $statut;

        return $this;
    }

    /** @return Collection<int, LigneReceptionAchat> */
    public function getLignes(): Collection
    {
        return $this->lignes;
    }

    public function addLigne(LigneReceptionAchat $ligne): self
    {
        if (!$this->lignes->contains($ligne)) {
            $this->lignes->add($ligne);
            $ligne->setReception($this);
        }

        return $this;
    }
}
