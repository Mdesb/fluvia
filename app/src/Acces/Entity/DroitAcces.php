<?php

declare(strict_types=1);

namespace App\Acces\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Acces\Enum\StatutProjectionDroit;
use App\Acces\Enum\TypeDroitAcces;
use App\Organisation\Entity\Etablissement;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Projection locale d'un droit vendu M1/M2 (point ouvert n°9 du plan) : cache la fenêtre de validité,
 * le crédit restant et les marges par défaut pour que le contrôleur valide en < 1 s (US-L3-03), y
 * compris hors-ligne. La source de vérité reste M1/M2 ; L3 consomme et décompte, ne crée pas le
 * droit. Alimentée par `ProjectionDroitInterface` (stub en L3).
 */
#[ORM\Entity]
#[ORM\Table(name: 'acces_droit_acces')]
#[ApiResource(
    shortName: 'DroitAcces',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'acces.lire')"),
        new Get(security: "is_granted('PERM', 'acces.lire')"),
    ],
    normalizationContext: ['groups' => ['droit:read']],
)]
class DroitAcces
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['droit:read', 'appairage:read', 'passage:read'])]
    private Uuid $id;

    #[ORM\Column(length: 24, enumType: TypeDroitAcces::class)]
    #[Groups(['droit:read', 'passage:read'])]
    private TypeDroitAcces $sourceType = TypeDroitAcces::Billet;

    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    #[Groups(['droit:read'])]
    private ?Uuid $billetSupportRef = null;

    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    #[Groups(['droit:read'])]
    private ?Uuid $produitRef = null;

    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    #[Groups(['droit:read'])]
    private ?Uuid $reservationRef = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['droit:read'])]
    private ?\DateTimeImmutable $fenetreDebut = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['droit:read'])]
    private ?\DateTimeImmutable $fenetreFin = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['droit:read'])]
    private ?int $creditRestant = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['droit:read'])]
    private ?int $margeAvanceDefaut = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['droit:read'])]
    private ?int $margeRetardDefaut = null;

    #[ORM\ManyToOne(targetEntity: SousReseau::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['droit:read'])]
    private ?SousReseau $sousReseau = null;

    /**
     * Les espaces que ce droit ouvre. VIDE = il les ouvre TOUS.
     *
     * ⚠ Le vide n'est pas une omission, c'est la règle de compatibilité. Les droits déjà projetés
     * n'en portent aucun : les refuser partout à la seconde où la migration passe fermerait des
     * portes devant des gens qui ont payé, sur un mécanisme dont ils ignorent le changement. La
     * restriction n'existe que si quelqu'un l'a demandée.
     *
     * Recopiés à la PROJECTION et non lus depuis le produit : c'est ce droit-ci que les terminaux
     * embarquent pour décider hors ligne. Une règle qui ne vivrait que côté produit serait
     * inapplicable par un lecteur déconnecté — c'est-à-dire précisément quand elle compte.
     *
     * @var Collection<int, EspaceAcces>
     */
    #[ORM\ManyToMany(targetEntity: EspaceAcces::class)]
    #[ORM\JoinTable(name: 'acces_droit_espace_autorise')]
    #[Groups(['droit:read'])]
    private Collection $authorisedSpaces;

    #[ORM\Column(length: 12, enumType: StatutProjectionDroit::class, options: ['default' => 'valide'])]
    #[Groups(['droit:read'])]
    private StatutProjectionDroit $statutProjection = StatutProjectionDroit::Valide;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['droit:read'])]
    private ?Etablissement $etablissement = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['droit:read'])]
    private ?\DateTimeImmutable $synchroniseLe = null;

    public function __construct()
    {
        $this->authorisedSpaces = new ArrayCollection();
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getSourceType(): TypeDroitAcces
    {
        return $this->sourceType;
    }

    public function setSourceType(TypeDroitAcces $sourceType): self
    {
        $this->sourceType = $sourceType;

        return $this;
    }

    public function getBilletSupportRef(): ?Uuid
    {
        return $this->billetSupportRef;
    }

    public function setBilletSupportRef(?Uuid $billetSupportRef): self
    {
        $this->billetSupportRef = $billetSupportRef;

        return $this;
    }

    public function getProduitRef(): ?Uuid
    {
        return $this->produitRef;
    }

    public function setProduitRef(?Uuid $produitRef): self
    {
        $this->produitRef = $produitRef;

        return $this;
    }

    public function getReservationRef(): ?Uuid
    {
        return $this->reservationRef;
    }

    public function setReservationRef(?Uuid $reservationRef): self
    {
        $this->reservationRef = $reservationRef;

        return $this;
    }

    public function getFenetreDebut(): ?\DateTimeImmutable
    {
        return $this->fenetreDebut;
    }

    public function setFenetreDebut(?\DateTimeImmutable $fenetreDebut): self
    {
        $this->fenetreDebut = $fenetreDebut;

        return $this;
    }

    public function getFenetreFin(): ?\DateTimeImmutable
    {
        return $this->fenetreFin;
    }

    public function setFenetreFin(?\DateTimeImmutable $fenetreFin): self
    {
        $this->fenetreFin = $fenetreFin;

        return $this;
    }

    public function getCreditRestant(): ?int
    {
        return $this->creditRestant;
    }

    public function setCreditRestant(?int $creditRestant): self
    {
        $this->creditRestant = $creditRestant;

        return $this;
    }

    public function getMargeAvanceDefaut(): ?int
    {
        return $this->margeAvanceDefaut;
    }

    public function setMargeAvanceDefaut(?int $margeAvanceDefaut): self
    {
        $this->margeAvanceDefaut = $margeAvanceDefaut;

        return $this;
    }

    public function getMargeRetardDefaut(): ?int
    {
        return $this->margeRetardDefaut;
    }

    public function setMargeRetardDefaut(?int $margeRetardDefaut): self
    {
        $this->margeRetardDefaut = $margeRetardDefaut;

        return $this;
    }

    public function getSousReseau(): ?SousReseau
    {
        return $this->sousReseau;
    }

    public function setSousReseau(?SousReseau $sousReseau): self
    {
        $this->sousReseau = $sousReseau;

        return $this;
    }

    public function getStatutProjection(): StatutProjectionDroit
    {
        return $this->statutProjection;
    }

    public function setStatutProjection(StatutProjectionDroit $statutProjection): self
    {
        $this->statutProjection = $statutProjection;

        return $this;
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

    public function getSynchroniseLe(): ?\DateTimeImmutable
    {
        return $this->synchroniseLe;
    }

    public function setSynchroniseLe(?\DateTimeImmutable $synchroniseLe): self
    {
        $this->synchroniseLe = $synchroniseLe;

        return $this;
    }

    /** @return Collection<int, EspaceAcces> */
    public function getAuthorisedSpaces(): Collection
    {
        return $this->authorisedSpaces;
    }

    public function addAuthorisedSpace(EspaceAcces $space): self
    {
        if (!$this->authorisedSpaces->contains($space)) {
            $this->authorisedSpaces->add($space);
        }

        return $this;
    }

    /**
     * Ce droit ouvre-t-il cet espace ?
     *
     * Vide = ouvre tout : voir le docbloc de la propriété. La comparaison porte sur la
     * représentation textuelle de l'identifiant — `getId()` rend des objets `Uuid`, qu'une
     * comparaison stricte d'objets distinguerait à tort (D58).
     */
    public function ouvre(EspaceAcces $space): bool
    {
        if ($this->authorisedSpaces->isEmpty()) {
            return true;
        }

        foreach ($this->authorisedSpaces as $autorise) {
            if ((string) $autorise->getId() === (string) $space->getId()) {
                return true;
            }
        }

        return false;
    }
}
