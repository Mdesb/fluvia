<?php

declare(strict_types=1);

namespace App\Boutique\Entity;

use App\Crm\Entity\Beneficiaire;
use App\Offre\Entity\Produit;
use App\Reservation\Entity\Creneau;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Ligne de panier en ligne (RG-M3-02/03, §4.5 spec). Le créneau est requis pour un produit
 * timed-entry (⚠ HYPOTHÈSE retenue : `Produit.champsPerso['timedEntry'] === true`, aucune facette
 * `timed_entry` définie côté M1 — cf. Risque n°2 du plan). Porte le bénéficiaire affecté à l'article
 * (§4.5) et l'autorisation parentale éventuelle (RG-M3-13).
 */
#[ORM\Entity]
#[ORM\Table(name: 'bou_ligne_panier')]
class LignePanierEnLigne
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['panier:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: PanierEnLigne::class, inversedBy: 'lignes')]
    #[ORM\JoinColumn(nullable: false)]
    private ?PanierEnLigne $panier = null;

    #[ORM\ManyToOne(targetEntity: Produit::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['panier:read'])]
    private ?Produit $produit = null;

    #[ORM\Column(options: ['default' => 1])]
    #[Groups(['panier:read'])]
    private int $quantite = 1;

    #[ORM\ManyToOne(targetEntity: Creneau::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['panier:read'])]
    private ?Creneau $creneau = null;

    #[ORM\ManyToOne(targetEntity: Beneficiaire::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['panier:read'])]
    private ?Beneficiaire $beneficiaireRef = null;

    /** @var array<string, mixed>|null {nom, prenom, dateNaissance?} — invité sans compte (§4.5). */
    #[ORM\Column(nullable: true)]
    #[Groups(['panier:read'])]
    private ?array $beneficiaireSimple = null;

    /** @var array<string, mixed>|null champs additionnels du produit (taille, niveau...) */
    #[ORM\Column(nullable: true)]
    #[Groups(['panier:read'])]
    private ?array $champsPersonnalises = null;

    #[ORM\Column(options: ['default' => false])]
    #[Groups(['panier:read'])]
    private bool $autorisationParentaleRequise = false;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['panier:read'])]
    private ?\DateTimeImmutable $autorisationParentaleHorodatage = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['panier:read'])]
    private \DateTimeImmutable $expirationA;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->expirationA = new \DateTimeImmutable('+15 minutes');
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getPanier(): ?PanierEnLigne
    {
        return $this->panier;
    }

    public function setPanier(?PanierEnLigne $panier): self
    {
        $this->panier = $panier;

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

    public function getQuantite(): int
    {
        return $this->quantite;
    }

    public function setQuantite(int $quantite): self
    {
        $this->quantite = $quantite;

        return $this;
    }

    public function getCreneau(): ?Creneau
    {
        return $this->creneau;
    }

    public function setCreneau(?Creneau $creneau): self
    {
        $this->creneau = $creneau;

        return $this;
    }

    public function getBeneficiaireRef(): ?Beneficiaire
    {
        return $this->beneficiaireRef;
    }

    public function setBeneficiaireRef(?Beneficiaire $beneficiaireRef): self
    {
        $this->beneficiaireRef = $beneficiaireRef;

        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getBeneficiaireSimple(): ?array
    {
        return $this->beneficiaireSimple;
    }

    /** @param array<string, mixed>|null $beneficiaireSimple */
    public function setBeneficiaireSimple(?array $beneficiaireSimple): self
    {
        $this->beneficiaireSimple = $beneficiaireSimple;

        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getChampsPersonnalises(): ?array
    {
        return $this->champsPersonnalises;
    }

    /** @param array<string, mixed>|null $champsPersonnalises */
    public function setChampsPersonnalises(?array $champsPersonnalises): self
    {
        $this->champsPersonnalises = $champsPersonnalises;

        return $this;
    }

    public function isAutorisationParentaleRequise(): bool
    {
        return $this->autorisationParentaleRequise;
    }

    public function setAutorisationParentaleRequise(bool $autorisationParentaleRequise): self
    {
        $this->autorisationParentaleRequise = $autorisationParentaleRequise;

        return $this;
    }

    public function getAutorisationParentaleHorodatage(): ?\DateTimeImmutable
    {
        return $this->autorisationParentaleHorodatage;
    }

    public function setAutorisationParentaleHorodatage(?\DateTimeImmutable $autorisationParentaleHorodatage): self
    {
        $this->autorisationParentaleHorodatage = $autorisationParentaleHorodatage;

        return $this;
    }

    public function getExpirationA(): \DateTimeImmutable
    {
        return $this->expirationA;
    }

    public function setExpirationA(\DateTimeImmutable $expirationA): self
    {
        $this->expirationA = $expirationA;

        return $this;
    }

    public function aBeneficiaire(): bool
    {
        return $this->beneficiaireRef !== null || $this->beneficiaireSimple !== null;
    }
}
