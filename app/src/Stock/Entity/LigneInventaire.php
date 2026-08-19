<?php

declare(strict_types=1);

namespace App\Stock\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Securite\Entity\Utilisateur;
use App\Stock\State\RegulariserLigneInventaireProcessor;
use App\Stock\State\SaisirComptageInventaireProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/** Ligne d'inventaire (RG-STOCK-12) : quantité théorique figée, écart dérivé, régularisation liée. */
#[ORM\Entity]
#[ORM\Table(name: 'stk_ligne_inventaire')]
#[ORM\UniqueConstraint(name: 'uniq_ligne_inventaire_mouvement', columns: ['mouvement_regularisation_id'])]
#[ApiResource(
    shortName: 'StockLigneInventaire',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'stock.lire')"),
        new Get(security: "is_granted('PERM', 'stock.lire')"),
        new Patch(
            uriTemplate: '/stock/lignes-inventaire/{id}',
            security: "is_granted('PERM', 'stock.inventorier') or is_granted('PERM', 'stock.gerer')",
            processor: SaisirComptageInventaireProcessor::class,
        ),
        new Post(
            uriTemplate: '/stock/lignes-inventaire/{id}/regulariser',
            read: true,
            input: false,
            security: "is_granted('PERM', 'stock.inventorier') or is_granted('PERM', 'stock.valider_ecart') or is_granted('PERM', 'stock.gerer')",
            processor: RegulariserLigneInventaireProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['ligne_inventaire:read']],
    denormalizationContext: ['groups' => ['ligne_inventaire:write']],
)]
class LigneInventaire
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['ligne_inventaire:read', 'inventaire:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Inventaire::class, inversedBy: 'lignes')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['ligne_inventaire:read'])]
    private ?Inventaire $inventaire = null;

    #[ORM\ManyToOne(targetEntity: ArticleStock::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['ligne_inventaire:read', 'inventaire:read'])]
    private ?ArticleStock $articleStock = null;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 3)]
    #[Groups(['ligne_inventaire:read', 'inventaire:read'])]
    private string $quantiteTheorique = '0.000';

    #[ORM\Column(type: 'decimal', precision: 12, scale: 3, nullable: true)]
    #[Groups(['ligne_inventaire:read', 'ligne_inventaire:write', 'inventaire:read'])]
    private ?string $quantiteComptee = null;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 3, nullable: true)]
    #[Groups(['ligne_inventaire:read', 'inventaire:read'])]
    private ?string $ecart = null;

    #[ORM\Column(options: ['default' => false])]
    #[Groups(['ligne_inventaire:read', 'inventaire:read'])]
    private bool $significatif = false;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['ligne_inventaire:read'])]
    private ?Utilisateur $valideParUtilisateur = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['ligne_inventaire:read'])]
    private ?\DateTimeImmutable $dateValidation = null;

    #[ORM\ManyToOne(targetEntity: MouvementStock::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['ligne_inventaire:read'])]
    private ?MouvementStock $mouvementRegularisation = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getInventaire(): ?Inventaire
    {
        return $this->inventaire;
    }

    public function setInventaire(?Inventaire $inventaire): self
    {
        $this->inventaire = $inventaire;

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

    public function getQuantiteTheorique(): string
    {
        return $this->quantiteTheorique;
    }

    public function setQuantiteTheorique(string $quantiteTheorique): self
    {
        $this->quantiteTheorique = $quantiteTheorique;

        return $this;
    }

    public function getQuantiteComptee(): ?string
    {
        return $this->quantiteComptee;
    }

    public function setQuantiteComptee(?string $quantiteComptee): self
    {
        $this->quantiteComptee = $quantiteComptee;

        return $this;
    }

    public function getEcart(): ?string
    {
        return $this->ecart;
    }

    public function setEcart(?string $ecart): self
    {
        $this->ecart = $ecart;

        return $this;
    }

    public function isSignificatif(): bool
    {
        return $this->significatif;
    }

    public function setSignificatif(bool $significatif): self
    {
        $this->significatif = $significatif;

        return $this;
    }

    public function getValideParUtilisateur(): ?Utilisateur
    {
        return $this->valideParUtilisateur;
    }

    public function setValideParUtilisateur(?Utilisateur $valideParUtilisateur): self
    {
        $this->valideParUtilisateur = $valideParUtilisateur;

        return $this;
    }

    public function getDateValidation(): ?\DateTimeImmutable
    {
        return $this->dateValidation;
    }

    public function setDateValidation(?\DateTimeImmutable $dateValidation): self
    {
        $this->dateValidation = $dateValidation;

        return $this;
    }

    public function getMouvementRegularisation(): ?MouvementStock
    {
        return $this->mouvementRegularisation;
    }

    public function setMouvementRegularisation(?MouvementStock $mouvementRegularisation): self
    {
        $this->mouvementRegularisation = $mouvementRegularisation;

        return $this;
    }
}
