<?php

declare(strict_types=1);

namespace App\Stock\Entity;

use ApiPlatform\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use App\Stock\Enum\TypeMouvementStock;
use App\Stock\State\AjustementMouvementProcessor;
use App\Stock\State\ReintegrationRetourProcessor;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Journal append-only des mouvements de stock (RG-STOCK-07/15) : ensemble fermé de types, motif
 * obligatoire sauf `entree_achat`/`sortie_vente`, coût requis pour toute sortie. Immuabilité garantie
 * par `MouvementStockInalterabiliteListener` (preUpdate/preRemove). Lecture seule côté API, sauf les
 * deux opérations métier ci-dessous (ajustement, réintégration retour, §4 du plan).
 */
#[ORM\Entity]
#[ORM\Table(name: 'stk_mouvement')]
#[ORM\Index(columns: ['article_stock_id', 'date'], name: 'idx_mouvement_article_date')]
#[ApiResource(
    shortName: 'StockMouvement',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'stock.lire')"),
        new Get(security: "is_granted('PERM', 'stock.lire')"),
        new Post(
            uriTemplate: '/stock/mouvements/ajustement',
            security: "is_granted('PERM', 'stock.ajuster')",
            processor: AjustementMouvementProcessor::class,
        ),
        new Post(
            uriTemplate: '/stock/mouvements/reintegration-retour',
            security: "is_granted('PERM', 'stock.ajuster')",
            processor: ReintegrationRetourProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['mouvement:read']],
)]
#[ApiFilter(SearchFilter::class, properties: ['articleStock' => 'exact', 'type' => 'exact'])]
#[ApiFilter(DateFilter::class, properties: ['date'])]
class MouvementStock
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['mouvement:read', 'imputation:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: ArticleStock::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['mouvement:read'])]
    private ?ArticleStock $articleStock = null;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['mouvement:read'])]
    private ?Etablissement $etablissement = null;

    #[ORM\Column(length: 32, enumType: TypeMouvementStock::class)]
    #[Groups(['mouvement:read'])]
    private TypeMouvementStock $type = TypeMouvementStock::EntreeAchat;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['mouvement:read'])]
    private \DateTimeImmutable $date;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 3)]
    #[Groups(['mouvement:read'])]
    private string $quantite = '0.000';

    #[ORM\Column(type: 'decimal', precision: 12, scale: 4, nullable: true)]
    #[Groups(['mouvement:read'])]
    private ?string $coutUnitaireCalcule = null;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2, nullable: true)]
    #[Groups(['mouvement:read'])]
    private ?string $coutTotalCalcule = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['mouvement:read'])]
    private ?string $motif = null;

    #[ORM\Column(length: 32, nullable: true)]
    #[Groups(['mouvement:read'])]
    private ?string $referenceType = null;

    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    #[Groups(['mouvement:read'])]
    private ?Uuid $referenceId = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['mouvement:read'])]
    private ?Utilisateur $auteur = null;

    /** @var Collection<int, ImputationLotStock> */
    #[ORM\OneToMany(targetEntity: ImputationLotStock::class, mappedBy: 'mouvementStock')]
    #[Groups(['mouvement:read'])]
    private Collection $imputations;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->date = new \DateTimeImmutable();
        $this->imputations = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getArticleStock(): ?ArticleStock
    {
        return $this->articleStock;
    }

    public function setArticleStock(?ArticleStock $articleStock): self
    {
        $this->articleStock = $articleStock;

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

    public function getType(): TypeMouvementStock
    {
        return $this->type;
    }

    public function setType(TypeMouvementStock $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function setDate(\DateTimeImmutable $date): self
    {
        $this->date = $date;

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

    public function getCoutUnitaireCalcule(): ?string
    {
        return $this->coutUnitaireCalcule;
    }

    public function setCoutUnitaireCalcule(?string $coutUnitaireCalcule): self
    {
        $this->coutUnitaireCalcule = $coutUnitaireCalcule;

        return $this;
    }

    public function getCoutTotalCalcule(): ?string
    {
        return $this->coutTotalCalcule;
    }

    public function setCoutTotalCalcule(?string $coutTotalCalcule): self
    {
        $this->coutTotalCalcule = $coutTotalCalcule;

        return $this;
    }

    public function getMotif(): ?string
    {
        return $this->motif;
    }

    public function setMotif(?string $motif): self
    {
        $this->motif = $motif;

        return $this;
    }

    public function getReferenceType(): ?string
    {
        return $this->referenceType;
    }

    public function setReferenceType(?string $referenceType): self
    {
        $this->referenceType = $referenceType;

        return $this;
    }

    public function getReferenceId(): ?Uuid
    {
        return $this->referenceId;
    }

    public function setReferenceId(?Uuid $referenceId): self
    {
        $this->referenceId = $referenceId;

        return $this;
    }

    public function getAuteur(): ?Utilisateur
    {
        return $this->auteur;
    }

    public function setAuteur(?Utilisateur $auteur): self
    {
        $this->auteur = $auteur;

        return $this;
    }

    /** @return Collection<int, ImputationLotStock> */
    public function getImputations(): Collection
    {
        return $this->imputations;
    }
}
