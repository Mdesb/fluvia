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
use App\Offre\Entity\Produit;
use App\Organisation\Entity\Etablissement;
use App\Stock\Enum\MethodeValorisation;
use App\Stock\Enum\Unite;
use App\Stock\State\DetacherProduitProcessor;
use App\Stock\State\RattacherProduitProcessor;
use App\Stock\Validator\CodeEanValide;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Article de stock (US-STOCK-01, RG-STOCK-01/02) : code-barres EAN, unité, prix d'achat, méthode de
 * valorisation surchargeable, seuils de réappro. Le rattachement à un `Produit` M1 (facette `stock`,
 * `Stock` dédié) passe par `RattacherProduitProcessor` (§0 décision n°1 du plan), jamais par `Patch`
 * direct du champ `produit` (non exposé en écriture standard). `UniqueEntity` (Doctrine bridge) donne
 * une réponse 422 propre (CA-1) plutôt qu'une erreur SQL brute sur la contrainte unique DB.
 */
#[ORM\Entity]
#[ORM\Table(name: 'stk_article')]
#[ORM\UniqueConstraint(name: 'uniq_article_ean_etab', columns: ['etablissement_id', 'code_ean'])]
#[ORM\UniqueConstraint(name: 'uniq_article_produit', columns: ['produit_id'])]
#[UniqueEntity(fields: ['etablissement', 'codeEAN'], message: 'Ce code-barres est déjà utilisé sur cet établissement (RG-STOCK-02).')]
#[ApiResource(
    shortName: 'ArticleStock',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'stock.lire')"),
        new Get(security: "is_granted('PERM', 'stock.lire')"),
        new Post(security: "is_granted('PERM', 'stock.gerer_article') or is_granted('PERM', 'stock.gerer')"),
        new Patch(security: "is_granted('PERM', 'stock.gerer_article') or is_granted('PERM', 'stock.gerer')"),
        new Post(
            uriTemplate: '/stock/articles/{id}/rattacher-produit',
            read: true,
            security: "is_granted('PERM', 'stock.gerer_article') or is_granted('PERM', 'stock.gerer')",
            processor: RattacherProduitProcessor::class,
        ),
        new Post(
            uriTemplate: '/stock/articles/{id}/detacher-produit',
            read: true,
            input: false,
            security: "is_granted('PERM', 'stock.gerer_article') or is_granted('PERM', 'stock.gerer')",
            processor: DetacherProduitProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['article:read']],
    denormalizationContext: ['groups' => ['article:write']],
)]
#[ApiFilter(SearchFilter::class, properties: ['codeEAN' => 'exact', 'libelle' => 'partial'])]
class ArticleStock
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['article:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['article:read', 'article:write'])]
    private ?Etablissement $etablissement = null;

    #[ORM\ManyToOne(targetEntity: Produit::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['article:read'])]
    private ?Produit $produit = null;

    #[ORM\Column(length: 13)]
    #[Assert\NotBlank]
    #[CodeEanValide]
    #[Groups(['article:read', 'article:write'])]
    private string $codeEAN = '';

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank]
    #[Groups(['article:read', 'article:write'])]
    private string $libelle = '';

    #[ORM\Column(length: 10, enumType: Unite::class)]
    #[Assert\NotNull]
    #[Groups(['article:read', 'article:write'])]
    private ?Unite $unite = Unite::Piece;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 4)]
    #[Assert\GreaterThanOrEqual(0)]
    #[Groups(['article:read', 'article:write'])]
    private string $prixAchatHT = '0.0000';

    #[ORM\Column(type: 'decimal', precision: 5, scale: 2)]
    #[Assert\GreaterThanOrEqual(0)]
    #[Groups(['article:read', 'article:write'])]
    private string $tauxTvaAchat = '0.00';

    #[ORM\Column(length: 4, enumType: MethodeValorisation::class, nullable: true)]
    #[Groups(['article:read', 'article:write'])]
    private ?MethodeValorisation $methodeValorisation = null;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 3, options: ['default' => '0.000'])]
    #[Assert\GreaterThanOrEqual(0)]
    #[Groups(['article:read', 'article:write'])]
    private string $seuilMin = '0.000';

    #[ORM\Column(type: 'decimal', precision: 12, scale: 3, options: ['default' => '0.000'])]
    #[Assert\GreaterThanOrEqual(0)]
    #[Groups(['article:read', 'article:write'])]
    private string $seuilMax = '0.000';

    #[ORM\Column(options: ['default' => true])]
    #[Groups(['article:read', 'article:write'])]
    private bool $actif = true;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['article:read'])]
    private \DateTimeImmutable $creeLe;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['article:read'])]
    private \DateTimeImmutable $modifieLe;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->creeLe = new \DateTimeImmutable();
        $this->modifieLe = new \DateTimeImmutable();
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

    public function getProduit(): ?Produit
    {
        return $this->produit;
    }

    public function setProduit(?Produit $produit): self
    {
        $this->produit = $produit;

        return $this;
    }

    public function getCodeEAN(): string
    {
        return $this->codeEAN;
    }

    public function setCodeEAN(string $codeEAN): self
    {
        $this->codeEAN = $codeEAN;

        return $this;
    }

    public function getLibelle(): string
    {
        return $this->libelle;
    }

    public function setLibelle(string $libelle): self
    {
        $this->libelle = $libelle;

        return $this;
    }

    public function getUnite(): ?Unite
    {
        return $this->unite;
    }

    public function setUnite(?Unite $unite): self
    {
        $this->unite = $unite;

        return $this;
    }

    public function getPrixAchatHT(): string
    {
        return $this->prixAchatHT;
    }

    public function setPrixAchatHT(string $prixAchatHT): self
    {
        $this->prixAchatHT = $prixAchatHT;

        return $this;
    }

    public function getTauxTvaAchat(): string
    {
        return $this->tauxTvaAchat;
    }

    public function setTauxTvaAchat(string $tauxTvaAchat): self
    {
        $this->tauxTvaAchat = $tauxTvaAchat;

        return $this;
    }

    public function getMethodeValorisation(): ?MethodeValorisation
    {
        return $this->methodeValorisation;
    }

    public function setMethodeValorisation(?MethodeValorisation $methodeValorisation): self
    {
        $this->methodeValorisation = $methodeValorisation;

        return $this;
    }

    public function getSeuilMin(): string
    {
        return $this->seuilMin;
    }

    public function setSeuilMin(string $seuilMin): self
    {
        $this->seuilMin = $seuilMin;

        return $this;
    }

    public function getSeuilMax(): string
    {
        return $this->seuilMax;
    }

    public function setSeuilMax(string $seuilMax): self
    {
        $this->seuilMax = $seuilMax;

        return $this;
    }

    public function isActif(): bool
    {
        return $this->actif;
    }

    public function setActif(bool $actif): self
    {
        $this->actif = $actif;

        return $this;
    }

    public function getCreeLe(): \DateTimeImmutable
    {
        return $this->creeLe;
    }

    public function getModifieLe(): \DateTimeImmutable
    {
        return $this->modifieLe;
    }

    public function toucherModifieLe(): self
    {
        $this->modifieLe = new \DateTimeImmutable();

        return $this;
    }
}
