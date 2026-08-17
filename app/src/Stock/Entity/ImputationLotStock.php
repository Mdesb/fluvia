<?php

declare(strict_types=1);

namespace App\Stock\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Ligne d'imputation d'un mouvement sur une couche de coût (traçabilité FIFO/LIFO, §2 du plan) —
 * append-only, snapshot du coût du lot au moment de l'imputation.
 */
#[ORM\Entity]
#[ORM\Table(name: 'stk_imputation_lot')]
#[ApiResource(
    shortName: 'StockImputationLot',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'stock.lire')"),
        new Get(security: "is_granted('PERM', 'stock.lire')"),
    ],
    normalizationContext: ['groups' => ['imputation:read']],
)]
#[ApiFilter(SearchFilter::class, properties: ['mouvementStock' => 'exact'])]
class ImputationLotStock
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['imputation:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: MouvementStock::class, inversedBy: 'imputations')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['imputation:read'])]
    private ?MouvementStock $mouvementStock = null;

    #[ORM\ManyToOne(targetEntity: LotStock::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['imputation:read'])]
    private ?LotStock $lotStock = null;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 3)]
    #[Groups(['imputation:read'])]
    private string $quantiteImputee = '0.000';

    #[ORM\Column(type: 'decimal', precision: 12, scale: 4)]
    #[Groups(['imputation:read'])]
    private string $coutUnitaire = '0.0000';

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getMouvementStock(): ?MouvementStock
    {
        return $this->mouvementStock;
    }

    public function setMouvementStock(?MouvementStock $mouvementStock): self
    {
        $this->mouvementStock = $mouvementStock;

        return $this;
    }

    public function getLotStock(): ?LotStock
    {
        return $this->lotStock;
    }

    public function setLotStock(?LotStock $lotStock): self
    {
        $this->lotStock = $lotStock;

        return $this;
    }

    public function getQuantiteImputee(): string
    {
        return $this->quantiteImputee;
    }

    public function setQuantiteImputee(string $quantiteImputee): self
    {
        $this->quantiteImputee = $quantiteImputee;

        return $this;
    }

    public function getCoutUnitaire(): string
    {
        return $this->coutUnitaire;
    }

    public function setCoutUnitaire(string $coutUnitaire): self
    {
        $this->coutUnitaire = $coutUnitaire;

        return $this;
    }
}
