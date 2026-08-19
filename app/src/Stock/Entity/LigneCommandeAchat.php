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

/** Ligne d'une commande d'achat (RG-STOCK-04). */
#[ORM\Entity]
#[ORM\Table(name: 'stk_ligne_commande_achat')]
#[ApiResource(
    shortName: 'StockLigneCommandeAchat',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'stock.lire')"),
        new Get(security: "is_granted('PERM', 'stock.lire')"),
        new Post(security: "is_granted('PERM', 'stock.gerer_achat') or is_granted('PERM', 'stock.gerer')"),
        new Patch(security: "is_granted('PERM', 'stock.gerer_achat') or is_granted('PERM', 'stock.gerer')"),
    ],
    normalizationContext: ['groups' => ['ligne_commande_achat:read']],
    denormalizationContext: ['groups' => ['ligne_commande_achat:write']],
)]
class LigneCommandeAchat
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['ligne_commande_achat:read', 'commande_achat:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: CommandeAchat::class, inversedBy: 'lignes')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['ligne_commande_achat:read', 'ligne_commande_achat:write'])]
    private ?CommandeAchat $commandeAchat = null;

    #[ORM\ManyToOne(targetEntity: ArticleStock::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['ligne_commande_achat:read', 'ligne_commande_achat:write', 'commande_achat:read'])]
    private ?ArticleStock $articleStock = null;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 3)]
    #[Assert\Positive]
    #[Groups(['ligne_commande_achat:read', 'ligne_commande_achat:write', 'commande_achat:read'])]
    private string $quantiteCommandee = '0.000';

    #[ORM\Column(type: 'decimal', precision: 12, scale: 4)]
    #[Assert\GreaterThanOrEqual(0)]
    #[Groups(['ligne_commande_achat:read', 'ligne_commande_achat:write', 'commande_achat:read'])]
    private string $prixAchatUnitaireHT = '0.0000';

    #[ORM\Column(type: 'decimal', precision: 5, scale: 2)]
    #[Assert\GreaterThanOrEqual(0)]
    #[Groups(['ligne_commande_achat:read', 'ligne_commande_achat:write', 'commande_achat:read'])]
    private string $tauxTVA = '0.00';

    #[ORM\Column(type: 'decimal', precision: 12, scale: 3, options: ['default' => '0.000'])]
    #[Groups(['ligne_commande_achat:read', 'commande_achat:read'])]
    private string $quantiteRecue = '0.000';

    public function __construct()
    {
        $this->id = Uuid::v4();
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

    public function getArticleStock(): ?ArticleStock
    {
        return $this->articleStock;
    }

    public function setArticleStock(?ArticleStock $articleStock): self
    {
        $this->articleStock = $articleStock;

        return $this;
    }

    public function getQuantiteCommandee(): string
    {
        return $this->quantiteCommandee;
    }

    public function setQuantiteCommandee(string $quantiteCommandee): self
    {
        $this->quantiteCommandee = $quantiteCommandee;

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

    public function getTauxTVA(): string
    {
        return $this->tauxTVA;
    }

    public function setTauxTVA(string $tauxTVA): self
    {
        $this->tauxTVA = $tauxTVA;

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
}
