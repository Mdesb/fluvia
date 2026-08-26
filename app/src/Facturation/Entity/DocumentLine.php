<?php

declare(strict_types=1);

namespace App\Facturation\Entity;

use App\Compta\Entity\TauxTva;
use App\Facturation\Enum\DocumentNature;
use App\Facturation\Service\Montant;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Une ligne de devis, de bon de commande ou de bon de livraison (FAC-1).
 *
 * **Le calcul est identique à celui de {@see LigneFacture}, et c'est délibéré.** Une ligne qui
 * arriverait sur la facture avec un montant différent de celui du quote ferait perdre au client la
 * confiance qu'on avait mise trois documents à construire. Même arithmétique, mêmes centimes entiers,
 * même arrondi.
 *
 * **`quantiteLivree` n'existe que pour le bon de livraison.** Une commande de dix articles dont sept
 * arrivent doit pouvoir le dire : facturer la commande plutôt que la livraison fait payer ce qui
 * n'est pas arrivé. Sur un devis ou une commande, elle vaut la quantité demandée.
 */
#[ORM\Entity]
#[ORM\Table(name: 'billing_document_line')]
class DocumentLine
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: CommercialDocument::class, inversedBy: 'lignes')]
    #[ORM\JoinColumn(nullable: false)]
    private ?CommercialDocument $document = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private string $designation = '';

    #[ORM\Column(type: 'integer')]
    #[Assert\Positive]
    private int $quantite = 1;

    /**
     * Ce qui a réellement été livré.
     *
     * Égale `quantite` partout ailleurs que sur un bon de livraison — voir le commentaire de classe.
     */
    #[ORM\Column(type: 'integer')]
    #[Assert\PositiveOrZero]
    private int $quantiteLivree = 0;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    private string $prixUnitaireHT = Montant::ZERO;

    #[ORM\ManyToOne(targetEntity: TauxTva::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?TauxTva $tauxTva = null;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    private string $montantHT = Montant::ZERO;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    private string $montantTva = Montant::ZERO;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    private string $montantTTC = Montant::ZERO;

    /** La ligne dont celle-ci est issue, quand le document reprend son prédécesseur. */
    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    private ?Uuid $ligneOrigine = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
    }

    /**
     * Recalcule les montants.
     *
     * **Sur un bon de livraison, la base est la quantité LIVRÉE.** C'est toute la raison d'être de ce
     * document : ce qui n'est pas arrivé ne se facture pas.
     */
    public function recalculer(): self
    {
        $base = DocumentNature::DeliveryNote === $this->document?->getNature()
            ? $this->quantiteLivree
            : $this->quantite;

        $ht = $base * Montant::enCentimes($this->prixUnitaireHT);
        $tva = (int) round($ht * ((float) $this->tauxValeur()) / 100.0);

        $this->montantHT = Montant::enDecimal($ht);
        $this->montantTva = Montant::enDecimal($tva);
        $this->montantTTC = Montant::enDecimal($ht + $tva);

        return $this;
    }

    public function tauxValeur(): string
    {
        return $this->tauxTva?->getTaux() ?? Montant::ZERO;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getDocument(): ?CommercialDocument
    {
        return $this->document;
    }

    public function setDocument(?CommercialDocument $document): self
    {
        $this->document = $document;

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

    public function getQuantite(): int
    {
        return $this->quantite;
    }

    /** Poser la quantité commandée aligne la quantité livrée : on livre ce qu'on commande, sauf avis contraire. */
    public function setQuantite(int $quantite): self
    {
        $this->quantite = $quantite;
        $this->quantiteLivree = $quantite;

        return $this;
    }

    public function getQuantiteLivree(): int
    {
        return $this->quantiteLivree;
    }

    public function setQuantiteLivree(int $quantiteLivree): self
    {
        $this->quantiteLivree = $quantiteLivree;

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

    public function getLigneOrigine(): ?Uuid
    {
        return $this->ligneOrigine;
    }

    public function setLigneOrigine(?Uuid $ligneOrigine): self
    {
        $this->ligneOrigine = $ligneOrigine;

        return $this;
    }
}
