<?php

declare(strict_types=1);

namespace App\Stock\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Stock\Validator\PrincipalUnique;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/** Catalogue fournisseur (US-STOCK-02, RG-STOCK-03) : référence, prix négocié, délai, principal. */
#[ORM\Entity]
#[ORM\Table(name: 'stk_catalogue_fournisseur')]
#[ORM\UniqueConstraint(name: 'uniq_catalogue_fournisseur_article', columns: ['fournisseur_id', 'article_stock_id'])]
#[PrincipalUnique]
#[ApiResource(
    shortName: 'StockCatalogueFournisseur',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'stock.lire')"),
        new Get(security: "is_granted('PERM', 'stock.lire')"),
        new Post(security: "is_granted('PERM', 'stock.gerer_fournisseur') or is_granted('PERM', 'stock.gerer')"),
        new Patch(security: "is_granted('PERM', 'stock.gerer_fournisseur') or is_granted('PERM', 'stock.gerer')"),
        new Delete(security: "is_granted('PERM', 'stock.gerer_fournisseur') or is_granted('PERM', 'stock.gerer')"),
    ],
    normalizationContext: ['groups' => ['catalogue_fournisseur:read']],
    denormalizationContext: ['groups' => ['catalogue_fournisseur:write']],
)]
#[ApiFilter(SearchFilter::class, properties: ['articleStock' => 'exact', 'fournisseur' => 'exact'])]
class CatalogueFournisseur
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['catalogue_fournisseur:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Fournisseur::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['catalogue_fournisseur:read', 'catalogue_fournisseur:write'])]
    private ?Fournisseur $fournisseur = null;

    #[ORM\ManyToOne(targetEntity: ArticleStock::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['catalogue_fournisseur:read', 'catalogue_fournisseur:write'])]
    private ?ArticleStock $articleStock = null;

    #[ORM\Column(length: 64, nullable: true)]
    #[Groups(['catalogue_fournisseur:read', 'catalogue_fournisseur:write'])]
    private ?string $referenceFournisseur = null;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 4)]
    #[Assert\GreaterThanOrEqual(0)]
    #[Groups(['catalogue_fournisseur:read', 'catalogue_fournisseur:write'])]
    private string $prixAchatNegocie = '0.0000';

    #[ORM\Column(type: 'smallint')]
    #[Assert\GreaterThanOrEqual(0)]
    #[Groups(['catalogue_fournisseur:read', 'catalogue_fournisseur:write'])]
    private int $delaiLivraisonJours = 0;

    #[ORM\Column(options: ['default' => false])]
    #[Groups(['catalogue_fournisseur:read', 'catalogue_fournisseur:write'])]
    private bool $principal = false;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
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

    public function getArticleStock(): ?ArticleStock
    {
        return $this->articleStock;
    }

    public function setArticleStock(?ArticleStock $articleStock): self
    {
        $this->articleStock = $articleStock;

        return $this;
    }

    public function getReferenceFournisseur(): ?string
    {
        return $this->referenceFournisseur;
    }

    public function setReferenceFournisseur(?string $referenceFournisseur): self
    {
        $this->referenceFournisseur = $referenceFournisseur;

        return $this;
    }

    public function getPrixAchatNegocie(): string
    {
        return $this->prixAchatNegocie;
    }

    public function setPrixAchatNegocie(string $prixAchatNegocie): self
    {
        $this->prixAchatNegocie = $prixAchatNegocie;

        return $this;
    }

    public function getDelaiLivraisonJours(): int
    {
        return $this->delaiLivraisonJours;
    }

    public function setDelaiLivraisonJours(int $delaiLivraisonJours): self
    {
        $this->delaiLivraisonJours = $delaiLivraisonJours;

        return $this;
    }

    public function isPrincipal(): bool
    {
        return $this->principal;
    }

    public function setPrincipal(bool $principal): self
    {
        $this->principal = $principal;

        return $this;
    }
}
