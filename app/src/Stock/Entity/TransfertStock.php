<?php

declare(strict_types=1);

namespace App\Stock\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Securite\Entity\Utilisateur;
use App\Stock\Enum\StatutTransfertStock;
use App\Stock\State\CreerTransfertProcessor;
use App\Stock\State\ExpedierTransfertProcessor;
use App\Stock\State\RecevoirTransfertProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Transfert inter-établissements (US-STOCK-10, RG-STOCK-13/14). ⚠ Écart assumé vs. spec §5 (qui ne
 * porte qu'un seul `articleStock`) : `RG-STOCK-13` impose qu'un `ArticleStock` soit rattaché à un seul
 * établissement, ce plan retient donc deux références distinctes source/destination (§1.6 du plan).
 */
#[ORM\Entity]
#[ORM\Table(name: 'stk_transfert')]
#[ApiResource(
    shortName: 'StockTransfert',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'stock.lire')"),
        new Get(security: "is_granted('PERM', 'stock.lire')"),
        new Post(
            input: false,
            security: "is_granted('PERM', 'stock.transferer') or is_granted('PERM', 'stock.gerer')",
            processor: CreerTransfertProcessor::class,
        ),
        new Post(
            uriTemplate: '/stock/transferts/{id}/expedier',
            read: true,
            input: false,
            security: "is_granted('PERM', 'stock.transferer') or is_granted('PERM', 'stock.gerer')",
            processor: ExpedierTransfertProcessor::class,
        ),
        new Post(
            uriTemplate: '/stock/transferts/{id}/recevoir',
            read: true,
            input: false,
            security: "is_granted('PERM', 'stock.transferer') or is_granted('PERM', 'stock.gerer')",
            processor: RecevoirTransfertProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['transfert:read']],
)]
class TransfertStock
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['transfert:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: ArticleStock::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['transfert:read'])]
    private ?ArticleStock $articleStockSource = null;

    #[ORM\ManyToOne(targetEntity: ArticleStock::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['transfert:read'])]
    private ?ArticleStock $articleStockDestination = null;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 3)]
    #[Groups(['transfert:read'])]
    private string $quantite = '0.000';

    #[ORM\Column(length: 10, enumType: StatutTransfertStock::class)]
    #[Groups(['transfert:read'])]
    private StatutTransfertStock $statut = StatutTransfertStock::Demande;

    #[ORM\ManyToOne(targetEntity: MouvementStock::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['transfert:read'])]
    private ?MouvementStock $mouvementSortie = null;

    #[ORM\ManyToOne(targetEntity: MouvementStock::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['transfert:read'])]
    private ?MouvementStock $mouvementEntree = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['transfert:read'])]
    private ?Utilisateur $demandePar = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['transfert:read'])]
    private \DateTimeImmutable $dateDemande;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['transfert:read'])]
    private ?\DateTimeImmutable $dateExpedition = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['transfert:read'])]
    private ?\DateTimeImmutable $dateReception = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->dateDemande = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getArticleStockSource(): ?ArticleStock
    {
        return $this->articleStockSource;
    }

    public function setArticleStockSource(?ArticleStock $articleStockSource): self
    {
        $this->articleStockSource = $articleStockSource;

        return $this;
    }

    public function getArticleStockDestination(): ?ArticleStock
    {
        return $this->articleStockDestination;
    }

    public function setArticleStockDestination(?ArticleStock $articleStockDestination): self
    {
        $this->articleStockDestination = $articleStockDestination;

        return $this;
    }

    public function getQuantite(): string
    {
        return $this->quantite;
    }

    public function setQuantite(string $quantite): self
    {
        $this->quantite = $quantite;

        return $this;
    }

    public function getStatut(): StatutTransfertStock
    {
        return $this->statut;
    }

    public function setStatut(StatutTransfertStock $statut): self
    {
        $this->statut = $statut;

        return $this;
    }

    public function getMouvementSortie(): ?MouvementStock
    {
        return $this->mouvementSortie;
    }

    public function setMouvementSortie(?MouvementStock $mouvementSortie): self
    {
        $this->mouvementSortie = $mouvementSortie;

        return $this;
    }

    public function getMouvementEntree(): ?MouvementStock
    {
        return $this->mouvementEntree;
    }

    public function setMouvementEntree(?MouvementStock $mouvementEntree): self
    {
        $this->mouvementEntree = $mouvementEntree;

        return $this;
    }

    public function getDemandePar(): ?Utilisateur
    {
        return $this->demandePar;
    }

    public function setDemandePar(?Utilisateur $demandePar): self
    {
        $this->demandePar = $demandePar;

        return $this;
    }

    public function getDateDemande(): \DateTimeImmutable
    {
        return $this->dateDemande;
    }

    public function setDateDemande(\DateTimeImmutable $dateDemande): self
    {
        $this->dateDemande = $dateDemande;

        return $this;
    }

    public function getDateExpedition(): ?\DateTimeImmutable
    {
        return $this->dateExpedition;
    }

    public function setDateExpedition(?\DateTimeImmutable $dateExpedition): self
    {
        $this->dateExpedition = $dateExpedition;

        return $this;
    }

    public function getDateReception(): ?\DateTimeImmutable
    {
        return $this->dateReception;
    }

    public function setDateReception(?\DateTimeImmutable $dateReception): self
    {
        $this->dateReception = $dateReception;

        return $this;
    }
}
