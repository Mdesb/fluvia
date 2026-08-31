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
use App\Stock\State\EstablishmentStampProcessor;
use App\Organisation\Entity\Etablissement;
use App\Stock\Enum\MethodeValorisation;
use App\Stock\Enum\Unite;
use App\Stock\State\DetacherProduitProcessor;
use App\Stock\State\RattacherProduitProcessor;
use App\Stock\Validator\CodeEanValide;
use App\Stock\Service\ArithmetiqueDecimale;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use App\Stock\Validator as AppAssert;
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
#[AppAssert\UniqueEanPerEstablishment]
#[ApiResource(
    shortName: 'ArticleStock',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'stock.lire')"),
        new Get(security: "is_granted('PERM', 'stock.lire')"),
        new Post(
            security: "is_granted('PERM', 'stock.gerer_article') or is_granted('PERM', 'stock.gerer')",
            processor: EstablishmentStampProcessor::class,
        ),
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
    // D41 — hors groupe d'ecriture : l'etablissement vient de la session serveur, pose par
    // `EstablishmentStampProcessor`, jamais du corps de la requete. Plus d'`Assert\NotNull` non plus :
    // la validation s'execute AVANT l'ecriture, donc avant l'estampillage, et echouerait en 422 sur
    // une valeur que le serveur allait poser lui-meme. L'invariant tient par l'estampilleur, qui
    // refuse plutot que de deviner, par la colonne NOT NULL, et par le garde global D41.
    #[Groups(['article:read'])]
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
    #[Groups(['article:read', 'article:write', 'ligne_commande_achat:read', 'mouvement:read'])]
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

    /**
     * Les lots de cet article — la **source de verite** des quantites du module.
     *
     * La relation n'existait pas : `LotStock` pointait vers l'article, jamais l'inverse. Sans elle, la
     * quantite reelle n'etait accessible qu'en agregeant les lots a la main, ce que l'ecran a du faire
     * — au prix de refuser d'afficher un total des que la pagination tronquait la liste.
     *
     * @var Collection<int, LotStock>
     */
    #[ORM\OneToMany(mappedBy: 'articleStock', targetEntity: LotStock::class)]
    private Collection $lots;

    public function __construct()
    {
        $this->lots = new ArrayCollection();
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

    /**
     * La quantite reellement disponible, agregee depuis les lots (RG-STOCK-08).
     *
     * **Pourquoi un getter calcule et non une colonne.** Un compteur entretenu a la main diverge des
     * lots des la premiere reception annulee ou le premier ajustement, et personne ne s'en apercoit
     * avant un inventaire. Recalcule, il ne peut pas mentir — c'est l'argument retenu pour
     * `ParcPatins::getQuantiteDisponible()`, et il vaut ici pour la meme raison.
     *
     * **Ce que ca debloque.** Le premier ecran de stock devait agreger les lots cote client, et donc
     * desactiver « Corriger » des que la pagination tronquait la liste : un agent qui voit « il reste
     * 12 », corrige a 14 alors qu'il en restait 47 sur des lots non charges, detruit son stock avec la
     * benediction du logiciel. Ce getter permet de retirer l'agregation **et** ce garde-fou.
     *
     * **Cout.** Une lecture charge les lots de l'article. Sur une liste, c'est un N+1 — acceptable
     * ici (quelques dizaines de lots par article, une reception en cree un), a surveiller si le parc
     * grossit : la sortie serait alors une requete agregee, pas un compteur stocke.
     */
    #[Groups(['article:read'])]
    public function getQuantiteDisponible(): string
    {
        $total = '0.000';
        foreach ($this->lots as $lot) {
            $total = ArithmetiqueDecimale::additionner($total, $lot->getQuantiteRestante(), 3);
        }

        return $total;
    }

    /** @return Collection<int, LotStock> */
    public function getLots(): Collection
    {
        return $this->lots;
    }
}
