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

    /**
     * Réf. logique TypeTarif (M1) — `null` quand personne n'a pu en fournir un.
     *
     * ⚠ NULLABLE DEPUIS LE 04/09, ET C'EST UN ARBITRAGE DE MAXIME. Le champ était obligatoire, et
     * `VenteReservationHandler` en tirait donc un AU HASARD (`Uuid::v4()`) pour les réservations
     * génériques : `Activite` ne porte pas de type de tarif, et son docblock dit que la chaîne de
     * tarification est hors périmètre du lot socle.
     *
     * Ce que ça coûte, mesuré : la comptabilité ne lit PAS ce champ. Son seul lecteur sur une ligne
     * est `LineLabelStamper`, qui résout le type pour figer son LIBELLÉ — une référence inventée
     * laisse le ticket sans « Plein tarif » ni « Membre ».
     *
     * ⚠ LE RENDU NE CHANGE PAS, SEUL LE MODÈLE CESSE DE PRÉTENDRE. Le ticket affiche exactement la
     * même chose qu'avant : rien. C'est ce qui rend le changement acceptable sur une entité de
     * socle — aucun écran ne bouge, aucune ligne existante n'est touchée.
     */
    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    #[Groups(['vente:read'])]
    private ?Uuid $typeTarif = null;

    /** Réf. logique Saison (M1) résolue au jour de la vente. */
    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    #[Groups(['vente:read'])]
    private ?Uuid $saison = null;

    /**
     * Réf. logique Activite (M5) — QUELLE PRESTATION a été vendue.
     *
     * ⚠ D'OÙ VIENT LA RECETTE, ET POURQUOI LE PRODUIT NE SUFFISAIT PAS. La ventilation comptable
     * s'appuie sur la CATÉGORIE du produit, pas sur le produit : multiplier les produits ne changeait
     * rien à la comptabilité, seulement à ce qu'on pouvait lire. Et rien sur cette ligne ne disait
     * d'où venait la vente — « combien la visite guidée a-t-elle rapporté » était sans réponse.
     *
     * `null` pour toute vente qui ne vient pas d'une réservation : une entrée au guichet n'a pas
     * d'activité, et lui en inventer une serait pire que le silence.
     */
    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    #[Groups(['vente:read'])]
    private ?Uuid $activite = null;

    /**
     * Réf. logique Ressource (M5) — QUEL ÉQUIPEMENT a été occupé.
     *
     * ⚠ ELLE NE FAIT PAS DOUBLON AVEC `activite`, ET C'EST MESURÉ. `ReserverTerrainProcessor` pose
     * une ressource et JAMAIS d'activité : en préproduction, 5 créneaux, 4 sans activité, 5 avec
     * ressource. L'activité seule laisserait 80 % des créneaux — et tout le padel — sans réponse.
     *
     * Et c'est elle qui mène au sport : `TerrainPadel::$sport`, posé le 04/09, se résout depuis la
     * ressource du terrain.
     */
    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    #[Groups(['vente:read'])]
    private ?Uuid $ressource = null;

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

    /**
     * Le taux de TVA que portait le produit ce jour-là — **exactement la même règle que le libellé**.
     *
     * Un taux légal change (la restauration est passée de 19,6 à 5,5 puis à 10). Recalculer la
     * ventilation d'une vente ancienne depuis le catalogue d'aujourd'hui la ferait mentir, et le
     * duplicata d'un ticket de l'an dernier annoncerait une TVA qui n'a jamais été collectée. Un
     * document opposable dit ce qui a été appliqué, pas ce qui s'appliquerait maintenant.
     *
     * ⚠ **Nul est une valeur, et elle n'est pas « 20 % ».** `Produit::$tauxTva` est nullable et
     * presque aucun produit ne le renseigne aujourd'hui. Poser un défaut ferait porter à un ticket
     * l'affirmation d'un taux que personne n'a choisi — la ventilation doit dire qu'elle est
     * incomplète, pas inventer. Voir `VentilationTvaVente`.
     */
    #[ORM\Column(type: 'decimal', precision: 5, scale: 2, nullable: true)]
    #[Groups(['vente:read', 'ticket:read'])]
    private ?string $tauxTva = null;

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

    public function getTypeTarif(): ?Uuid
    {
        return $this->typeTarif;
    }

    public function setTypeTarif(?Uuid $typeTarif): self
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

    public function getTauxTva(): ?string
    {
        return $this->tauxTva;
    }

    public function setTauxTva(?string $tauxTva): self
    {
        $this->tauxTva = $tauxTva;

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

    public function getActivite(): ?Uuid
    {
        return $this->activite;
    }

    public function setActivite(?Uuid $activite): self
    {
        $this->activite = $activite;

        return $this;
    }

    public function getRessource(): ?Uuid
    {
        return $this->ressource;
    }

    public function setRessource(?Uuid $ressource): self
    {
        $this->ressource = $ressource;

        return $this;
    }
}
