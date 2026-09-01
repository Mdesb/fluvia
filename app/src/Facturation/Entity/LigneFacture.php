<?php

declare(strict_types=1);

namespace App\Facturation\Entity;

use App\Compta\Entity\TauxTva;
use App\Facturation\Service\Montant;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
use App\Facturation\Enum\UnitCode;

/**
 * Ligne d'une facture (`plan-facturation.md` §1.2, `spec-facturation.md` §5).
 *
 * Aucune ligne ne peut exister sans **taux de TVA** : RG-M6-05 est réutilisée telle quelle
 * (ventilation ligne à ligne, jamais de taux moyen). `categorieComptable` (référence logique vers une
 * catégorie M1) permet de résoudre le **compte de produit** via le `MappingComptable` de M6 (facture
 * directe uniquement) ; à défaut, repli sur `ParametreFacturationEtablissement::$compteProduitDefaut`.
 */
#[ORM\Entity]
#[ORM\Table(name: 'facturation_ligne')]
class LigneFacture
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['facture:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Facture::class, inversedBy: 'lignes')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Facture $facture = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Groups(['facture:read'])]
    private string $designation = '';

    /** Réf. logique `App\Vente\Entity\LigneVente` (M2), sans FK — requise si `facture.origine=ticket_encaisse`. */
    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    #[Groups(['facture:read'])]
    private ?Uuid $ligneVenteOrigine = null;

    /** Réf. logique catégorie comptable (M1) — résout le compte produit via `MappingComptable` (M6). */
    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    #[Groups(['facture:read'])]
    private ?Uuid $categorieComptable = null;

    #[ORM\Column(options: ['default' => 1])]
    #[Assert\Positive]
    #[Groups(['facture:read'])]
    private int $quantite = 1;

    /**
     * BT-130 — l'unite de mesure de la quantite ci-dessus.
     *
     * ⚠ ELLE N'EXISTAIT PAS, ET `quantite` SEULE NE VEUT RIEN DIRE. « 3 » ne dit pas trois heures,
     * trois entrees ou trois mois. EN 16931 refuse une ligne sans BT-130, et un client qui relit sa
     * facture n'a pas plus d'information que le validateur.
     *
     * Le defaut `C62` — « one » dans la recommandation 20 de l'UN/ECE — explicite ce qui etait deja
     * vrai : une quantite sans unite comptait des CHOSES. Il n'invente rien (D66-ter).
     */
    #[ORM\Column(length: 3, enumType: UnitCode::class, options: ['default' => 'C62'])]
    #[Groups(['facture:read'])]
    private UnitCode $unitCode = UnitCode::Piece;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, options: ['default' => '0.00'])]
    #[Groups(['facture:read'])]
    private string $prixUnitaireHT = Montant::ZERO;

    #[ORM\ManyToOne(targetEntity: TauxTva::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['facture:read'])]
    private ?TauxTva $tauxTva = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, options: ['default' => '0.00'])]
    #[Groups(['facture:read'])]
    private string $montantHT = Montant::ZERO;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, options: ['default' => '0.00'])]
    #[Groups(['facture:read'])]
    private string $montantTva = Montant::ZERO;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, options: ['default' => '0.00'])]
    #[Groups(['facture:read'])]
    private string $montantTTC = Montant::ZERO;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getFacture(): ?Facture
    {
        return $this->facture;
    }

    public function setFacture(?Facture $facture): self
    {
        $this->facture = $facture;

        return $this;
    }

    public function getDesignation(): string
    {
        return $this->designation;
    }

    public function setDesignation(string $designation): self
    {
        $this->designation = $designation;

        return $this;
    }

    public function getLigneVenteOrigine(): ?Uuid
    {
        return $this->ligneVenteOrigine;
    }

    public function setLigneVenteOrigine(?Uuid $ligneVenteOrigine): self
    {
        $this->ligneVenteOrigine = $ligneVenteOrigine;

        return $this;
    }

    public function getCategorieComptable(): ?Uuid
    {
        return $this->categorieComptable;
    }

    public function setCategorieComptable(?Uuid $categorieComptable): self
    {
        $this->categorieComptable = $categorieComptable;

        return $this;
    }

    public function getUnitCode(): UnitCode
    {
        return $this->unitCode;
    }

    public function setUnitCode(UnitCode $unitCode): self
    {
        $this->unitCode = $unitCode;

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

    public function getPrixUnitaireHT(): string
    {
        return $this->prixUnitaireHT;
    }

    public function setPrixUnitaireHT(string $prixUnitaireHT): self
    {
        $this->prixUnitaireHT = Montant::normaliser($prixUnitaireHT);

        return $this;
    }

    public function getTauxTva(): ?TauxTva
    {
        return $this->tauxTva;
    }

    public function setTauxTva(?TauxTva $tauxTva): self
    {
        $this->tauxTva = $tauxTva;

        return $this;
    }

    /** Valeur du taux sous forme de chaîne normalisée (« 20.00 »), clé de ventilation. */
    public function getTauxTvaValeur(): string
    {
        return Montant::normaliser($this->tauxTva?->getTaux() ?? Montant::ZERO);
    }

    public function getMontantHT(): string
    {
        return $this->montantHT;
    }

    public function getMontantTva(): string
    {
        return $this->montantTva;
    }

    public function getMontantTTC(): string
    {
        return $this->montantTTC;
    }

    /** Recalcule HT/TVA/TTC de la ligne. Appelé par `Facture::recalculerTotaux()`. */
    public function recalculer(): self
    {
        $ht = $this->quantite * Montant::enCentimes($this->prixUnitaireHT);
        $tva = (int) round($ht * ((float) $this->getTauxTvaValeur()) / 100.0);

        $this->montantHT = Montant::enDecimal($ht);
        $this->montantTva = Montant::enDecimal($tva);
        $this->montantTTC = Montant::enDecimal($ht + $tva);

        return $this;
    }
}
