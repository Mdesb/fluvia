<?php

declare(strict_types=1);

namespace App\Stock\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Organisation\Entity\Etablissement;
use App\Stock\Enum\OrigineLotStock;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Couche de coût (RG-STOCK-08) : quantité initiale figée, quantité restante décrémentée à chaque
 * consommation FIFO/LIFO, coût unitaire figé à l'entrée (jamais réécrit). Lecture seule côté API
 * (créé exclusivement par les handlers métier — réception, ajustement, transfert, inventaire).
 */
#[ORM\Entity]
#[ORM\Table(name: 'stk_lot')]
#[ORM\Index(columns: ['article_stock_id', 'date_entree'], name: 'idx_lot_article_date')]
#[ApiResource(
    shortName: 'StockLot',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'stock.lire')"),
        new Get(security: "is_granted('PERM', 'stock.lire')"),
    ],
    normalizationContext: ['groups' => ['lot:read']],
)]
#[ApiFilter(SearchFilter::class, properties: ['articleStock' => 'exact'])]
class LotStock
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['lot:read', 'ligne_reception_achat:read', 'reception_achat:read', 'imputation:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: ArticleStock::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['lot:read'])]
    private ?ArticleStock $articleStock = null;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['lot:read'])]
    private ?Etablissement $etablissement = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['lot:read'])]
    private \DateTimeImmutable $dateEntree;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 3)]
    #[Groups(['lot:read'])]
    private string $quantiteInitiale = '0.000';

    #[ORM\Column(type: 'decimal', precision: 12, scale: 3)]
    #[Groups(['lot:read'])]
    private string $quantiteRestante = '0.000';

    #[ORM\Column(type: 'decimal', precision: 12, scale: 4)]
    #[Groups(['lot:read'])]
    private string $coutUnitaireHT = '0.0000';

    #[ORM\Column(length: 32, enumType: OrigineLotStock::class)]
    #[Groups(['lot:read'])]
    private OrigineLotStock $origine = OrigineLotStock::Reception;

    #[ORM\Column(length: 32, nullable: true)]
    #[Groups(['lot:read'])]
    private ?string $referenceOrigineType = null;

    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    #[Groups(['lot:read'])]
    private ?Uuid $referenceOrigineId = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->dateEntree = new \DateTimeImmutable();
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

    public function getDateEntree(): \DateTimeImmutable
    {
        return $this->dateEntree;
    }

    public function setDateEntree(\DateTimeImmutable $dateEntree): self
    {
        $this->dateEntree = $dateEntree;

        return $this;
    }

    public function getQuantiteInitiale(): string
    {
        return $this->quantiteInitiale;
    }

    public function setQuantiteInitiale(string $quantiteInitiale): self
    {
        $this->quantiteInitiale = $quantiteInitiale;

        return $this;
    }

    public function getQuantiteRestante(): string
    {
        return $this->quantiteRestante;
    }

    public function setQuantiteRestante(string $quantiteRestante): self
    {
        $this->quantiteRestante = $quantiteRestante;

        return $this;
    }

    public function getCoutUnitaireHT(): string
    {
        return $this->coutUnitaireHT;
    }

    public function setCoutUnitaireHT(string $coutUnitaireHT): self
    {
        $this->coutUnitaireHT = $coutUnitaireHT;

        return $this;
    }

    public function getOrigine(): OrigineLotStock
    {
        return $this->origine;
    }

    public function setOrigine(OrigineLotStock $origine): self
    {
        $this->origine = $origine;

        return $this;
    }

    public function getReferenceOrigineType(): ?string
    {
        return $this->referenceOrigineType;
    }

    public function setReferenceOrigineType(?string $referenceOrigineType): self
    {
        $this->referenceOrigineType = $referenceOrigineType;

        return $this;
    }

    public function getReferenceOrigineId(): ?Uuid
    {
        return $this->referenceOrigineId;
    }

    public function setReferenceOrigineId(?Uuid $referenceOrigineId): self
    {
        $this->referenceOrigineId = $referenceOrigineId;

        return $this;
    }
}
