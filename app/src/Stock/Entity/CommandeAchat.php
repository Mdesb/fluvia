<?php

declare(strict_types=1);

namespace App\Stock\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Organisation\Entity\Etablissement;
use App\Stock\Enum\StatutCommandeAchat;
use App\Stock\State\AnnulerCommandeAchatProcessor;
use App\Stock\State\ConfirmerCommandeAchatProcessor;
use App\Stock\State\EnvoyerCommandeAchatProcessor;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Commande d'achat (US-STOCK-03, RG-STOCK-04) : cycle brouillon → envoyée → confirmée →
 * (partiellement_reçue) → reçue → clôturée, ou annulée avant réception. Aucun mouvement de stock tant
 * qu'aucune réception n'est validée (CA-4).
 */
#[ORM\Entity]
#[ORM\Table(name: 'stk_commande_achat')]
#[ORM\UniqueConstraint(name: 'uniq_commande_achat_numero', columns: ['numero'])]
#[ApiResource(
    shortName: 'StockCommandeAchat',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'stock.lire')"),
        new Get(security: "is_granted('PERM', 'stock.lire')"),
        new Post(security: "is_granted('PERM', 'stock.gerer_achat')"),
        new Patch(security: "is_granted('PERM', 'stock.gerer_achat')"),
        new Post(
            uriTemplate: '/stock/commandes-achat/{id}/envoyer',
            read: true,
            input: false,
            security: "is_granted('PERM', 'stock.gerer_achat')",
            processor: EnvoyerCommandeAchatProcessor::class,
        ),
        new Post(
            uriTemplate: '/stock/commandes-achat/{id}/confirmer',
            read: true,
            input: false,
            security: "is_granted('PERM', 'stock.gerer_achat')",
            processor: ConfirmerCommandeAchatProcessor::class,
        ),
        new Post(
            uriTemplate: '/stock/commandes-achat/{id}/annuler',
            read: true,
            input: false,
            security: "is_granted('PERM', 'stock.gerer_achat')",
            processor: AnnulerCommandeAchatProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['commande_achat:read']],
    denormalizationContext: ['groups' => ['commande_achat:write']],
)]
#[ApiFilter(SearchFilter::class, properties: ['statut' => 'exact', 'fournisseur' => 'exact'])]
class CommandeAchat
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['commande_achat:read', 'ligne_commande_achat:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['commande_achat:read', 'commande_achat:write'])]
    private ?Etablissement $etablissement = null;

    #[ORM\ManyToOne(targetEntity: Fournisseur::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['commande_achat:read', 'commande_achat:write'])]
    private ?Fournisseur $fournisseur = null;

    #[ORM\Column(length: 32)]
    #[Groups(['commande_achat:read'])]
    private string $numero = '';

    #[ORM\Column(length: 20, enumType: StatutCommandeAchat::class)]
    #[Groups(['commande_achat:read'])]
    private StatutCommandeAchat $statut = StatutCommandeAchat::Brouillon;

    #[ORM\Column(type: 'date_immutable')]
    #[Assert\NotNull]
    #[Groups(['commande_achat:read', 'commande_achat:write'])]
    private ?\DateTimeImmutable $dateCommande = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    #[Groups(['commande_achat:read'])]
    private ?\DateTimeImmutable $dateLivraisonPrevue = null;

    /** @var Collection<int, LigneCommandeAchat> */
    #[ORM\OneToMany(targetEntity: LigneCommandeAchat::class, mappedBy: 'commandeAchat', cascade: ['persist'], orphanRemoval: true)]
    #[Groups(['commande_achat:read'])]
    private Collection $lignes;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->dateCommande = new \DateTimeImmutable();
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

    public function getFournisseur(): ?Fournisseur
    {
        return $this->fournisseur;
    }

    public function setFournisseur(?Fournisseur $fournisseur): self
    {
        $this->fournisseur = $fournisseur;

        return $this;
    }

    public function getNumero(): string
    {
        return $this->numero;
    }

    public function setNumero(string $numero): self
    {
        $this->numero = $numero;

        return $this;
    }

    public function getStatut(): StatutCommandeAchat
    {
        return $this->statut;
    }

    public function setStatut(StatutCommandeAchat $statut): self
    {
        $this->statut = $statut;

        return $this;
    }

    public function getDateCommande(): ?\DateTimeImmutable
    {
        return $this->dateCommande;
    }

    public function setDateCommande(?\DateTimeImmutable $dateCommande): self
    {
        $this->dateCommande = $dateCommande;

        return $this;
    }

    public function getDateLivraisonPrevue(): ?\DateTimeImmutable
    {
        return $this->dateLivraisonPrevue;
    }

    public function setDateLivraisonPrevue(?\DateTimeImmutable $dateLivraisonPrevue): self
    {
        $this->dateLivraisonPrevue = $dateLivraisonPrevue;

        return $this;
    }

    /** @return Collection<int, LigneCommandeAchat> */
    public function getLignes(): Collection
    {
        return $this->lignes;
    }

    public function addLigne(LigneCommandeAchat $ligne): self
    {
        if (!$this->lignes->contains($ligne)) {
            $this->lignes->add($ligne);
            $ligne->setCommandeAchat($this);
        }

        return $this;
    }
}
