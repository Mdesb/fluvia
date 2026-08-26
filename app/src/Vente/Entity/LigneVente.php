<?php

declare(strict_types=1);

namespace App\Vente\Entity;

use App\Vente\Enum\RemiseType;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Ligne de panier (LigneCommande). Le prix unitaire provient de la grille M1 (ResolveurPrix) ;
 * forçable seulement avec le droit vente.forcer_prix (RG-M1-01). Le bénéficiaire est porté par la
 * ligne et requis si le produit est nominatif (RG-M2-04 / CA-5). Les promotions éligibles sont
 * appliquées automatiquement et visibles (US-L2-03).
 */
#[ORM\Entity]
#[ORM\Table(name: 'vente_ligne')]
class LigneVente
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['vente:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Vente::class, inversedBy: 'lignes')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Vente $vente = null;

    /** Réf. logique Produit (M1). */
    #[ORM\Column(type: UuidType::NAME)]
    #[Groups(['vente:read'])]
    private Uuid $produit;

    /** Réf. logique TypeTarif (M1). */
    #[ORM\Column(type: UuidType::NAME)]
    #[Groups(['vente:read'])]
    private Uuid $typeTarif;

    /** Réf. logique Saison (M1) résolue au jour de la vente. */
    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    #[Groups(['vente:read'])]
    private ?Uuid $saison = null;

    /**
     * Le nom que portait le produit **le jour de la vente**.
     *
     * ⚠ **Ce libellé est une copie datée, pas une référence.** Un ticket dit ce que le produit
     * s'appelait le jour où il a été vendu. Remplacer ce champ par une jointure sur `Produit` ferait
     * **mentir rétroactivement tous les tickets déjà émis** le jour où quelqu'un renomme un article —
     * et un duplicata tiré six mois plus tard n'aurait plus rien à voir avec l'original.
     *
     * Ce n'est donc pas une redondance à normaliser. C'est la même règle que `prixUnitaire`, stocké et
     * jamais recalculé, et que `optionsSelectionnees`, figé par RG-OPT-09. `LibelleFigeTest` le
     * vérifie : un test qui échoue est plus difficile à supprimer qu'un commentaire.
     *
     * Nul quand la référence ne désigne aucun produit du catalogue — voir `LineLabelStamper`.
     *
     * @var array<string, string>|null
     */
    #[ORM\Column(nullable: true)]
    #[Groups(['vente:read', 'ticket:read'])]
    private ?array $libelleProduit = null;

    /** Le nom que portait le tarif ce jour-là. Même règle que ci-dessus : copie datée. */
    #[ORM\Column(length: 120, nullable: true)]
    #[Groups(['vente:read', 'ticket:read'])]
    private ?string $libelleTypeTarif = null;

    #[ORM\Column(options: ['default' => 1])]
    #[Groups(['vente:read'])]
    private int $quantite = 1;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    #[Groups(['vente:read'])]
    private string $prixUnitaire = '0.00';

    #[ORM\Column(options: ['default' => false])]
    #[Groups(['vente:read'])]
    private bool $prixForce = false;

    /** Réf. logique Personne/Client (M4) — requis si produit nominatif (RG-M2-04). */
    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    #[Groups(['vente:read'])]
    private ?Uuid $beneficiaire = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    #[Groups(['vente:read'])]
    private ?string $remiseLigne = null;

    #[ORM\Column(length: 12, enumType: RemiseType::class, nullable: true)]
    #[Groups(['vente:read'])]
    private ?RemiseType $remiseType = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['vente:read'])]
    private ?string $note = null;

    /** @var list<string>|null UUID de Promotion (M1) appliquées automatiquement (US-L2-03). */
    #[ORM\Column(nullable: true)]
    #[Groups(['vente:read'])]
    private ?array $promotionsAppliquees = null;

    /**
     * Sélection d'options (App\OptionProduit) figée à l'ajout au panier (RG-OPT-09), même patron que
     * `promotionsAppliquees`.
     *
     * @var list<array{groupeOptionId: string, valeurOptionId: string, libelle: string, impactType: string, impactValeur: string, montantUnitaireApplique: string}>|null
     */
    #[ORM\Column(nullable: true)]
    #[Groups(['vente:read'])]
    private ?array $optionsSelectionnees = null;

    /** Σ des impacts unitaires des options sélectionnées (RG-OPT-04), ajoutée à `prixUnitaire`. */
    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, options: ['default' => '0.00'])]
    #[Groups(['vente:read'])]
    private string $impactOptionsUnitaire = '0.00';

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, options: ['default' => '0.00'])]
    #[Groups(['vente:read'])]
    private string $montantLigne = '0.00';

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->produit = Uuid::v4();
        $this->typeTarif = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function setId(Uuid $id): self
    {
        $this->id = $id;

        return $this;
    }

    public function getVente(): ?Vente
    {
        return $this->vente;
    }

    public function setVente(?Vente $vente): self
    {
        $this->vente = $vente;

        return $this;
    }

    public function getProduit(): Uuid
    {
        return $this->produit;
    }

    public function setProduit(Uuid $produit): self
    {
        $this->produit = $produit;

        return $this;
    }

    public function getTypeTarif(): Uuid
    {
        return $this->typeTarif;
    }

    public function setTypeTarif(Uuid $typeTarif): self
    {
        $this->typeTarif = $typeTarif;

        return $this;
    }

    public function getSaison(): ?Uuid
    {
        return $this->saison;
    }

    public function setSaison(?Uuid $saison): self
    {
        $this->saison = $saison;

        return $this;
    }

    /** @return array<string, string>|null */
    public function getLibelleProduit(): ?array
    {
        return $this->libelleProduit;
    }

    /** @param array<string, string>|null $libelleProduit */
    public function setLibelleProduit(?array $libelleProduit): self
    {
        $this->libelleProduit = $libelleProduit;

        return $this;
    }

    public function getLibelleTypeTarif(): ?string
    {
        return $this->libelleTypeTarif;
    }

    public function setLibelleTypeTarif(?string $libelleTypeTarif): self
    {
        $this->libelleTypeTarif = $libelleTypeTarif;

        return $this;
    }

    public function getQuantite(): int
    {
        return $this->quantite;
    }

    public function setQuantite(int $quantite): self
    {
        $this->quantite = $quantite;

        return $this;
    }

    public function getPrixUnitaire(): string
    {
        return $this->prixUnitaire;
    }

    public function setPrixUnitaire(string $prixUnitaire): self
    {
        $this->prixUnitaire = $prixUnitaire;

        return $this;
    }

    public function isPrixForce(): bool
    {
        return $this->prixForce;
    }

    public function setPrixForce(bool $prixForce): self
    {
        $this->prixForce = $prixForce;

        return $this;
    }

    public function getBeneficiaire(): ?Uuid
    {
        return $this->beneficiaire;
    }

    public function setBeneficiaire(?Uuid $beneficiaire): self
    {
        $this->beneficiaire = $beneficiaire;

        return $this;
    }

    public function getRemiseLigne(): ?string
    {
        return $this->remiseLigne;
    }

    public function setRemiseLigne(?string $remiseLigne): self
    {
        $this->remiseLigne = $remiseLigne;

        return $this;
    }

    public function getRemiseType(): ?RemiseType
    {
        return $this->remiseType;
    }

    public function setRemiseType(?RemiseType $remiseType): self
    {
        $this->remiseType = $remiseType;

        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): self
    {
        $this->note = $note;

        return $this;
    }

    /** @return list<string>|null */
    public function getPromotionsAppliquees(): ?array
    {
        return $this->promotionsAppliquees;
    }

    /** @param list<string>|null $promotionsAppliquees */
    public function setPromotionsAppliquees(?array $promotionsAppliquees): self
    {
        $this->promotionsAppliquees = $promotionsAppliquees;

        return $this;
    }

    /** @return list<array{groupeOptionId: string, valeurOptionId: string, libelle: string, impactType: string, impactValeur: string, montantUnitaireApplique: string}>|null */
    public function getOptionsSelectionnees(): ?array
    {
        return $this->optionsSelectionnees;
    }

    /** @param list<array{groupeOptionId: string, valeurOptionId: string, libelle: string, impactType: string, impactValeur: string, montantUnitaireApplique: string}>|null $optionsSelectionnees */
    public function setOptionsSelectionnees(?array $optionsSelectionnees): self
    {
        $this->optionsSelectionnees = $optionsSelectionnees;

        return $this;
    }

    public function getImpactOptionsUnitaire(): string
    {
        return $this->impactOptionsUnitaire;
    }

    public function setImpactOptionsUnitaire(string $impactOptionsUnitaire): self
    {
        $this->impactOptionsUnitaire = $impactOptionsUnitaire;

        return $this;
    }

    public function getMontantLigne(): string
    {
        return $this->montantLigne;
    }

    public function setMontantLigne(string $montantLigne): self
    {
        $this->montantLigne = $montantLigne;

        return $this;
    }
}
