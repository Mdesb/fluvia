<?php

declare(strict_types=1);

namespace App\Offre\Entity;

use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Offre\Enum\AxeCategorie;
use App\Offre\Enum\Canal;
use App\Offre\Enum\ReglePca;
use App\Offre\Enum\StatutProduit;
use App\Offre\State\ActionsDeMasseProcessor;
use App\Offre\State\ArchiverProcessor;
use App\Offre\State\ConvertirProcessor;
use App\Offre\State\DepublierProcessor;
use App\Offre\State\DupliquerProcessor;
use App\Offre\State\ProduitProcessor;
use App\Offre\State\PublierProcessor;
use App\Offre\State\ReactiverProcessor;
use App\Organisation\Entity\Etablissement;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
use App\Vente\Dto\PriceQuote;
use App\Vente\State\PriceQuoteProvider;

/**
 * Produit générique à facettes (RG-M1-02) : un modèle unique couvre les 4 métiers. Le type
 * pilote les onglets/facettes visibles. Le type est verrouillé après création (RG-M1-11 / CA-4) :
 * il n'est présent qu'en écriture de création (groupe produit:create) et modifiable ensuite
 * uniquement via la conversion assistée. L'onglet Compta (reglePca, compte, TVA) est isolé dans
 * le groupe produit:compta et protégé par offre.modifier_compta.
 */
#[ORM\Entity]
#[ORM\Table(name: 'off_produit')]
#[ORM\UniqueConstraint(name: 'uniq_produit_code', columns: ['code'])]
#[ApiResource(
    shortName: 'Produit',
    operations: [
        new GetCollection(
            security: "is_granted('PERM', 'offre.lire')",
            normalizationContext: ['groups' => ['produit:read', 'produit:list']],
        ),
        new Get(
            security: "is_granted('PERM', 'offre.lire')",
            normalizationContext: ['groups' => ['produit:read', 'produit:compta']],
        ),
        new Post(
            security: "is_granted('PERM', 'offre.creer')",
            processor: ProduitProcessor::class,
            denormalizationContext: ['groups' => ['produit:write', 'produit:create']],
        ),
        new Patch(
            security: "is_granted('PERM', 'offre.modifier')",
            processor: ProduitProcessor::class,
            denormalizationContext: ['groups' => ['produit:write']],
        ),
        new Patch(
            uriTemplate: '/produits/{id}/compta',
            security: "is_granted('PERM', 'offre.modifier_compta')",
            processor: ProduitProcessor::class,
            denormalizationContext: ['groups' => ['produit:compta']],
            normalizationContext: ['groups' => ['produit:read', 'produit:compta']],
        ),
        // Le prix applicable AVANT qu'une vente existe, et la raison qui l'explique.
        //
        // Tant qu'aucune vente n'est ouverte, la caisse ne peut qu'estimer — et elle estimait mal :
        // elle retenait la premiere grille vendable du produit, quand le serveur applique le tarif
        // reellement du (saison, quotient familial). Maxime a vu le resultat : « 1 x Test 10,00 EUR »
        // pour un total de 15,00 EUR.
        //
        // `PriceQuoteProvider` ne recalcule rien : il appelle le MEME service que la composition d'une
        // ligne de vente. Une seconde implementation aurait reproduit le defaut dans une couche ou
        // plus personne ne verrait la divergence.
        new Get(
            uriTemplate: '/produits/{id}/tarif',
            description: 'Prix applicable et son motif. Parametres : typeTarif (obligatoire), canal?, date?, qf?. Ne cree rien.',
            security: "is_granted('PERM', 'offre.lire')",
            output: PriceQuote::class,
            normalizationContext: ['groups' => ['tarif:read']],
            provider: PriceQuoteProvider::class,
        ),
        new Post(
            uriTemplate: '/produits/{id}/publier',
            read: true,
            input: false,
            security: "is_granted('PERM', 'offre.publier')",
            processor: PublierProcessor::class,
        ),
        new Post(
            uriTemplate: '/produits/{id}/depublier',
            read: true,
            input: false,
            security: "is_granted('PERM', 'offre.publier')",
            processor: DepublierProcessor::class,
        ),
        new Post(
            uriTemplate: '/produits/{id}/archiver',
            read: true,
            input: false,
            security: "is_granted('PERM', 'offre.archiver')",
            processor: ArchiverProcessor::class,
        ),
        new Post(
            uriTemplate: '/produits/{id}/reactiver',
            read: true,
            input: false,
            security: "is_granted('PERM', 'offre.modifier')",
            processor: ReactiverProcessor::class,
        ),
        new Post(
            uriTemplate: '/produits/{id}/dupliquer',
            read: true,
            input: false,
            security: "is_granted('PERM', 'offre.creer')",
            processor: DupliquerProcessor::class,
        ),
        new Post(
            uriTemplate: '/produits/{id}/convertir',
            read: true,
            input: false,
            security: "is_granted('PERM', 'offre.modifier')",
            processor: ConvertirProcessor::class,
        ),
        new Post(
            uriTemplate: '/produits/actions-de-masse',
            read: false,
            input: false,
            security: "is_granted('PERM', 'offre.modifier')",
            processor: ActionsDeMasseProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['produit:read']],
    denormalizationContext: ['groups' => ['produit:write']],
)]
#[ApiFilter(SearchFilter::class, properties: [
    'code' => 'partial',
    'libelleRecherche' => 'partial',
    'statut' => 'exact',
    'typeCode' => 'exact',
    'type' => 'exact',
    'categories' => 'exact',
])]
#[ApiFilter(OrderFilter::class, properties: ['code', 'statut', 'libelleRecherche', 'creeLe', 'modifieLe'])]
class Produit
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['produit:read', 'produit:list'])]
    private Uuid $id;

    /** @var array<string, string> Libellé i18n {locale: valeur}. */
    #[ORM\Column]
    #[Assert\NotBlank(message: 'Le libellé est obligatoire.')]
    #[Groups(['produit:read', 'produit:list', 'produit:write'])]
    private array $libelle = [];

    /** Projection texte du libellé pour la recherche catalogue (CA-1). Non écrite directement. */
    #[ORM\Column(length: 512, nullable: true)]
    #[Groups(['produit:read'])]
    private ?string $libelleRecherche = null;

    #[ORM\Column(length: 64)]
    #[Groups(['produit:read', 'produit:list', 'produit:write'])]
    private string $code = '';

    #[ORM\ManyToOne(targetEntity: TypeProduit::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull(message: 'Le type de produit est obligatoire.')]
    #[Groups(['produit:read', 'produit:list', 'produit:create'])]
    private ?TypeProduit $type = null;

    /** Projection du code de type pour le filtrage catalogue (CA-1), synchronisée sur le type. */
    #[ORM\Column(length: 48, nullable: true)]
    #[Groups(['produit:read', 'produit:list'])]
    private ?string $typeCode = null;

    #[ORM\Column(length: 16, enumType: StatutProduit::class, options: ['default' => 'brouillon'])]
    #[Groups(['produit:read', 'produit:list'])]
    private StatutProduit $statut = StatutProduit::Brouillon;

    /** @var list<string> Canaux de vente ⊂ {guichet,en_ligne,borne} (RG-M1-09). */
    #[ORM\Column]
    #[Groups(['produit:read', 'produit:write'])]
    private array $canaux = [];

    #[ORM\Column(type: 'dateinterval', nullable: true)]
    #[Groups(['produit:read', 'produit:write'])]
    private ?\DateInterval $dureeValidite = null;

    /**
     * Combien d'entrées vaut un billet de ce produit pendant sa durée de validité : une par défaut
     * (décision de Maxime du 08/10). `null` = illimité dans la durée, pour les allers-retours.
     * Recopié sur le droit d'accès à la vente (`StubProjectionDroit`), sans effet sur une carte ni
     * sur une formule, qui ont leurs propres règles. Au moins 1 : contrôlé par `ProduitProcessor`.
     */
    #[ORM\Column(nullable: true, options: ['default' => 1])]
    #[Groups(['produit:read', 'produit:write'])]
    private ?int $entryCount = 1;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['produit:read', 'produit:write'])]
    private ?string $noteInterne = null;

    /** @var array<string, string>|null */
    #[ORM\Column(nullable: true)]
    #[Groups(['produit:read', 'produit:write'])]
    private ?array $description = null;

    #[ORM\Column(length: 9, nullable: true)]
    #[Groups(['produit:read', 'produit:write'])]
    private ?string $couleurCaisse = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(nullable: true)]
    #[Groups(['produit:read', 'produit:write'])]
    private ?array $champsPerso = null;

    // --- Onglet Compta (isolé, RG-M1-08) ---

    #[ORM\Column(length: 24, enumType: ReglePca::class, options: ['default' => 'aucune'])]
    #[Groups(['produit:read', 'produit:compta'])]
    private ReglePca $reglePca = ReglePca::Aucune;

    #[ORM\Column(length: 32, nullable: true)]
    #[Groups(['produit:compta'])]
    private ?string $compteComptable = null;

    #[ORM\Column(type: 'decimal', precision: 5, scale: 2, nullable: true)]
    #[Groups(['produit:compta'])]
    private ?string $tauxTva = null;

    /** @var Collection<int, Etablissement> Sites de commercialisation (RG-M1-09, ≥1 pour publier). */
    #[ORM\ManyToMany(targetEntity: Etablissement::class)]
    #[ORM\JoinTable(name: 'off_produit_etablissement')]
    #[Groups(['produit:read', 'produit:write'])]
    private Collection $etablissements;

    /** @var Collection<int, Categorie> Une valeur par axe (RG-M1-05). */
    #[ORM\ManyToMany(targetEntity: Categorie::class)]
    #[ORM\JoinTable(name: 'off_produit_categorie')]
    #[Groups(['produit:read', 'produit:write'])]
    private Collection $categories;

    #[ORM\OneToOne(targetEntity: Formule::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\JoinColumn(nullable: true)]
    #[Assert\Valid]
    #[Groups(['produit:read', 'produit:write'])]
    private ?Formule $formule = null;

    #[ORM\OneToOne(targetEntity: CarteMultiEntrees::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\JoinColumn(nullable: true)]
    #[Assert\Valid]
    #[Groups(['produit:read', 'produit:write'])]
    private ?CarteMultiEntrees $carte = null;

    #[ORM\ManyToOne(targetEntity: Stock::class, cascade: ['persist'])]
    #[ORM\JoinColumn(nullable: true)]
    #[Assert\Valid]
    #[Groups(['produit:read', 'produit:write'])]
    private ?Stock $stock = null;

    /** @var Collection<int, GrilleTarifaire> */
    #[ORM\OneToMany(targetEntity: GrilleTarifaire::class, mappedBy: 'produit')]
    #[Groups(['produit:read'])]
    private Collection $grilles;

    /**
     * Autorisation parentale exigée pour un bénéficiaire mineur, à l'achat en ligne (#101).
     *
     * ⚠ UN CHOIX MÉTIER DE L'ÉTABLISSEMENT, PAS UNE EXIGENCE DU RGPD. L'avis juridique du 04/10 l'a
     * dit : rien n'impose de faire cocher une autorisation pour acheter un billet à un mineur. C'est
     * le règlement intérieur qui peut l'exiger (mineur non accompagné). Décoché par défaut : la
     * boutique ne pose la question que là où l'exploitant l'a voulue.
     */
    #[ORM\Column(options: ['default' => false])]
    #[Groups(['produit:read', 'produit:write'])]
    private bool $parentalConsentRequired = false;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['produit:read', 'produit:list'])]
    private \DateTimeImmutable $creeLe;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['produit:read', 'produit:list'])]
    private \DateTimeImmutable $modifieLe;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->etablissements = new ArrayCollection();
        $this->categories = new ArrayCollection();
        $this->grilles = new ArrayCollection();
        $this->creeLe = new \DateTimeImmutable();
        $this->modifieLe = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function isParentalConsentRequired(): bool
    {
        return $this->parentalConsentRequired;
    }

    public function setParentalConsentRequired(bool $parentalConsentRequired): self
    {
        $this->parentalConsentRequired = $parentalConsentRequired;

        return $this;
    }

    /** @return array<string, string> */
    public function getLibelle(): array
    {
        return $this->libelle;
    }

    /** @param array<string, string> $libelle */
    public function setLibelle(array $libelle): self
    {
        $this->libelle = $libelle;

        return $this;
    }

    public function getLibelleRecherche(): ?string
    {
        return $this->libelleRecherche;
    }

    public function setLibelleRecherche(?string $libelleRecherche): self
    {
        $this->libelleRecherche = $libelleRecherche;

        return $this;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): self
    {
        $this->code = $code;

        return $this;
    }

    public function getType(): ?TypeProduit
    {
        return $this->type;
    }

    public function setType(?TypeProduit $type): self
    {
        $this->type = $type;
        $this->typeCode = $type?->getCode();

        return $this;
    }

    public function getTypeCode(): ?string
    {
        return $this->typeCode;
    }

    public function getStatut(): StatutProduit
    {
        return $this->statut;
    }

    public function setStatut(StatutProduit $statut): self
    {
        $this->statut = $statut;

        return $this;
    }

    /** @return list<string> */
    public function getCanaux(): array
    {
        return $this->canaux;
    }

    /** @param list<string> $canaux */
    public function setCanaux(array $canaux): self
    {
        $this->canaux = array_values($canaux);

        return $this;
    }

    public function getDureeValidite(): ?\DateInterval
    {
        return $this->dureeValidite;
    }

    public function setDureeValidite(?\DateInterval $dureeValidite): self
    {
        $this->dureeValidite = $dureeValidite;

        return $this;
    }

    public function getEntryCount(): ?int
    {
        return $this->entryCount;
    }

    public function setEntryCount(?int $entryCount): self
    {
        $this->entryCount = $entryCount;

        return $this;
    }

    public function getNoteInterne(): ?string
    {
        return $this->noteInterne;
    }

    public function setNoteInterne(?string $noteInterne): self
    {
        $this->noteInterne = $noteInterne;

        return $this;
    }

    /** @return array<string, string>|null */
    public function getDescription(): ?array
    {
        return $this->description;
    }

    /** @param array<string, string>|null $description */
    public function setDescription(?array $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function getCouleurCaisse(): ?string
    {
        return $this->couleurCaisse;
    }

    public function setCouleurCaisse(?string $couleurCaisse): self
    {
        $this->couleurCaisse = $couleurCaisse;

        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getChampsPerso(): ?array
    {
        return $this->champsPerso;
    }

    /** @param array<string, mixed>|null $champsPerso */
    public function setChampsPerso(?array $champsPerso): self
    {
        $this->champsPerso = $champsPerso;

        return $this;
    }

    public function getReglePca(): ReglePca
    {
        return $this->reglePca;
    }

    public function setReglePca(ReglePca $reglePca): self
    {
        $this->reglePca = $reglePca;

        return $this;
    }

    public function getCompteComptable(): ?string
    {
        return $this->compteComptable;
    }

    public function setCompteComptable(?string $compteComptable): self
    {
        $this->compteComptable = $compteComptable;

        return $this;
    }

    public function getTauxTva(): ?string
    {
        return $this->tauxTva;
    }

    public function setTauxTva(?string $tauxTva): self
    {
        $this->tauxTva = $tauxTva;

        return $this;
    }

    /** @return Collection<int, Etablissement> */
    public function getEtablissements(): Collection
    {
        return $this->etablissements;
    }

    public function addEtablissement(Etablissement $etablissement): self
    {
        if (!$this->etablissements->contains($etablissement)) {
            $this->etablissements->add($etablissement);
        }

        return $this;
    }

    public function removeEtablissement(Etablissement $etablissement): self
    {
        $this->etablissements->removeElement($etablissement);

        return $this;
    }

    /** @return Collection<int, Categorie> */
    public function getCategories(): Collection
    {
        return $this->categories;
    }

    /**
     * ⚠ CE SETTER EXISTE PARCE QUE SANS LUI, `categories` NE S'ECRIT PAS DU TOUT PAR L'API.
     *
     * Le PropertyAccessor de Symfony ecrit une collection par un couple `addX`/`removeX` construit
     * sur le SINGULIER ANGLAIS du nom de propriete. Mesure :
     *
     *     singularize('categories')      -> ['category']       <- l'entite offre `addCategorie`
     *     singularize('etablissements')  -> ['etablissement']  <- l'entite offre `addEtablissement`
     *
     * « categories » tombe sur la regle `ies -> y`. Aucun couple ne correspond, et API Platform
     * SAUTE LE CHAMP SANS RIEN DIRE : `PATCH` repondait 200, rendait les anciennes categories, et
     * n'ecrivait rien. Le selecteur de la fiche produit ecrivait dans le vide.
     *
     * ⚠ Le contraste est la preuve : `etablissements`, sur la MEME entite, dans le MEME groupe,
     * s'ecrivait tres bien — parce que son pluriel anglais retombe sur son adder.
     *
     * Un setter explicite prend le pas sur cette recherche et ne depend plus d'aucune regle de
     * langue. On remplace le contenu sans changer d'instance de collection : Doctrine suit les
     * ajouts et retraits de CELLE-CI, et lui en substituer une autre lui ferait perdre le fil.
     *
     * @param iterable<Categorie> $categories
     */
    public function setCategories(iterable $categories): self
    {
        $voulues = [];
        foreach ($categories as $categorie) {
            $voulues[(string) $categorie->getId()] = $categorie;
        }

        foreach ($this->categories->toArray() as $presente) {
            if (!isset($voulues[(string) $presente->getId()])) {
                $this->categories->removeElement($presente);
            }
        }

        foreach ($voulues as $categorie) {
            if (!$this->categories->contains($categorie)) {
                $this->categories->add($categorie);
            }
        }

        return $this;
    }

    public function addCategorie(Categorie $categorie): self
    {
        if (!$this->categories->contains($categorie)) {
            $this->categories->add($categorie);
        }

        return $this;
    }

    public function removeCategorie(Categorie $categorie): self
    {
        $this->categories->removeElement($categorie);

        return $this;
    }

    public function getCategorieParAxe(AxeCategorie $axe): ?Categorie
    {
        foreach ($this->categories as $categorie) {
            if ($categorie->getAxe() === $axe) {
                return $categorie;
            }
        }

        return null;
    }

    public function aCategorieComptable(): bool
    {
        return $this->getCategorieParAxe(AxeCategorie::Comptable) !== null;
    }

    public function getFormule(): ?Formule
    {
        return $this->formule;
    }

    public function setFormule(?Formule $formule): self
    {
        $this->formule = $formule;

        return $this;
    }

    public function getCarte(): ?CarteMultiEntrees
    {
        return $this->carte;
    }

    public function setCarte(?CarteMultiEntrees $carte): self
    {
        $this->carte = $carte;

        return $this;
    }

    public function getStock(): ?Stock
    {
        return $this->stock;
    }

    public function setStock(?Stock $stock): self
    {
        $this->stock = $stock;

        return $this;
    }

    /** @return Collection<int, GrilleTarifaire> */
    public function getGrilles(): Collection
    {
        return $this->grilles;
    }

    public function addGrille(GrilleTarifaire $grille): self
    {
        if (!$this->grilles->contains($grille)) {
            $this->grilles->add($grille);
            $grille->setProduit($this);
        }

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

    /** Vrai s'il existe au moins un prix renseigné (non null = commercialisé), RG-M1-09. */
    public function aPrixValide(): bool
    {
        foreach ($this->grilles as $grille) {
            if ($grille->getPrix() !== null) {
                return true;
            }
        }

        return false;
    }

    public function aCanal(Canal $canal): bool
    {
        return \in_array($canal->value, $this->canaux, true);
    }
}
