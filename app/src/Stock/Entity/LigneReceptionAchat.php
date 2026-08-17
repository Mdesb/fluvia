<?php

declare(strict_types=1);

namespace App\Stock\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/** Ligne d'une réception d'achat (RG-STOCK-05, CA-5). Le lot est créé à la validation, jamais avant. */
#[ORM\Entity]
#[ORM\Table(name: 'stk_ligne_reception_achat')]
#[ORM\UniqueConstraint(name: 'uniq_ligne_reception_lot', columns: ['lot_cree_id'])]
#[ApiResource(
    shortName: 'StockLigneReceptionAchat',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'stock.lire')"),
        new Get(security: "is_granted('PERM', 'stock.lire')"),
        new Post(security: "is_granted('PERM', 'stock.receptionner')"),
        new Patch(security: "is_granted('PERM', 'stock.receptionner')"),
    ],
    normalizationContext: ['groups' => ['ligne_reception_achat:read']],
    denormalizationContext: ['groups' => ['ligne_reception_achat:write']],
)]
class LigneReceptionAchat
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['ligne_reception_achat:read', 'reception_achat:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: ReceptionAchat::class, inversedBy: 'lignes')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['ligne_reception_achat:read', 'ligne_reception_achat:write'])]
    private ?ReceptionAchat $reception = null;

    #[ORM\ManyToOne(targetEntity: ArticleStock::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['ligne_reception_achat:read', 'ligne_reception_achat:write', 'reception_achat:read'])]
    private ?ArticleStock $articleStock = null;

    #[ORM\ManyToOne(targetEntity: LigneCommandeAchat::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['ligne_reception_achat:read', 'ligne_reception_achat:write', 'reception_achat:read'])]
    private ?LigneCommandeAchat $ligneCommandeAchat = null;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 3)]
    #[Assert\Positive]
    #[Groups(['ligne_reception_achat:read', 'ligne_reception_achat:write', 'reception_achat:read'])]
    private string $quantiteRecue = '0.000';

    #[ORM\Column(type: 'decimal', precision: 12, scale: 4)]
    #[Assert\GreaterThanOrEqual(0)]
    #[Groups(['ligne_reception_achat:read', 'ligne_reception_achat:write', 'reception_achat:read'])]
    private string $prixAchatUnitaireHT = '0.0000';

    #[ORM\OneToOne(targetEntity: LotStock::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['ligne_reception_achat:read', 'reception_achat:read'])]
    private ?LotStock $lotCree = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getReception(): ?ReceptionAchat
    {
        return $this->reception;
    }

    public function setReception(?ReceptionAchat $reception): self
    {
        $this->reception = $reception;

        return $this;
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

    public function getLigneCommandeAchat(): ?LigneCommandeAchat
    {
        return $this->ligneCommandeAchat;
    }

    public function setLigneCommandeAchat(?LigneCommandeAchat $ligneCommandeAchat): self
    {
        $this->ligneCommandeAchat = $ligneCommandeAchat;

        return $this;
    }

    public function getQuantiteRecue(): string
    {
        return $this->quantiteRecue;
    }

    public function setQuantiteRecue(string $quantiteRecue): self
    {
        $this->quantiteRecue = $quantiteRecue;

        return $this;
    }

    public function getPrixAchatUnitaireHT(): string
    {
        return $this->prixAchatUnitaireHT;
    }

    public function setPrixAchatUnitaireHT(string $prixAchatUnitaireHT): self
    {
        $this->prixAchatUnitaireHT = $prixAchatUnitaireHT;

        return $this;
    }

    public function getLotCree(): ?LotStock
    {
        return $this->lotCree;
    }

    public function setLotCree(?LotStock $lotCree): self
    {
        $this->lotCree = $lotCree;

        return $this;
    }
}
