<?php

declare(strict_types=1);

namespace App\Patinoire\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Organisation\Entity\Etablissement;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Parc de patins déclinés par pointure (RG-PAT-01, RG-PAT-06, US-PATIN-01). Les compteurs
 * `quantiteSortie`/`quantiteEnAffutage`/`quantiteHS` sont des **compteurs cachés maintenus par les
 * handlers** à chaque transition (même patron que `Ressource.occupationCourante`, plan §0 point 2) —
 * pas d'agrégation SQL à la volée. `produitLocationRef` est une référence **logique** (uuid, non-FK)
 * vers `App\Offre\Entity\Produit` (stock dédié RG-M1-10), même patron que
 * `App\Padel\Entity\ParametragePadel.produitTerrainRef` (aucun concept de variante/pointure natif M1,
 * spec §8).
 */
#[ORM\Entity]
#[ORM\Table(name: 'patin_parc_patins')]
#[ORM\UniqueConstraint(name: 'uniq_parc_patins_etab_pointure', columns: ['etablissement_id', 'pointure'])]
#[ApiResource(
    shortName: 'PatinoireParcPatins',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'patinoire.lire')"),
        new Get(security: "is_granted('PERM', 'patinoire.lire')"),
        new Post(security: "is_granted('PERM', 'patinoire.configurer')"),
        new Patch(security: "is_granted('PERM', 'patinoire.configurer')"),
    ],
    normalizationContext: ['groups' => ['parc_patins:read']],
    denormalizationContext: ['groups' => ['parc_patins:write']],
)]
#[ApiFilter(SearchFilter::class, properties: ['etablissement' => 'exact', 'pointure' => 'exact', 'actif' => 'exact'])]
class ParcPatins
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['parc_patins:read', 'location:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['parc_patins:read', 'parc_patins:write'])]
    private ?Etablissement $etablissement = null;

    #[ORM\Column(type: 'smallint')]
    #[Assert\Range(min: 28, max: 48)]
    #[Groups(['parc_patins:read', 'parc_patins:write', 'location:read'])]
    private int $pointure = 28;

    /** Réf. logique Produit (M1, facette stock) — RG-M1-10, pas de FK réelle (patron ParametragePadel). */
    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    #[Groups(['parc_patins:read', 'parc_patins:write'])]
    private ?Uuid $produitLocationRef = null;

    #[ORM\Column(type: 'smallint')]
    #[Assert\PositiveOrZero]
    #[Groups(['parc_patins:read', 'parc_patins:write'])]
    private int $quantiteTotale = 0;

    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    #[Groups(['parc_patins:read'])]
    private int $quantiteSortie = 0;

    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    #[Groups(['parc_patins:read'])]
    private int $quantiteEnAffutage = 0;

    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    #[Groups(['parc_patins:read'])]
    private int $quantiteHS = 0;

    #[ORM\Column(options: ['default' => true])]
    #[Groups(['parc_patins:read', 'parc_patins:write'])]
    private bool $actif = true;

    public function __construct()
    {
        $this->id = Uuid::v4();
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

    public function getPointure(): int
    {
        return $this->pointure;
    }

    public function setPointure(int $pointure): self
    {
        $this->pointure = $pointure;

        return $this;
    }

    public function getProduitLocationRef(): ?Uuid
    {
        return $this->produitLocationRef;
    }

    public function setProduitLocationRef(?Uuid $produitLocationRef): self
    {
        $this->produitLocationRef = $produitLocationRef;

        return $this;
    }

    public function getQuantiteTotale(): int
    {
        return $this->quantiteTotale;
    }

    public function setQuantiteTotale(int $quantiteTotale): self
    {
        $this->quantiteTotale = $quantiteTotale;

        return $this;
    }

    public function getQuantiteSortie(): int
    {
        return $this->quantiteSortie;
    }

    public function setQuantiteSortie(int $quantiteSortie): self
    {
        $this->quantiteSortie = $quantiteSortie;

        return $this;
    }

    public function incrementerSortie(int $delta = 1): self
    {
        $this->quantiteSortie = max(0, $this->quantiteSortie + $delta);

        return $this;
    }

    public function getQuantiteEnAffutage(): int
    {
        return $this->quantiteEnAffutage;
    }

    public function setQuantiteEnAffutage(int $quantiteEnAffutage): self
    {
        $this->quantiteEnAffutage = $quantiteEnAffutage;

        return $this;
    }

    public function incrementerEnAffutage(int $delta = 1): self
    {
        $this->quantiteEnAffutage = max(0, $this->quantiteEnAffutage + $delta);

        return $this;
    }

    public function getQuantiteHS(): int
    {
        return $this->quantiteHS;
    }

    public function setQuantiteHS(int $quantiteHS): self
    {
        $this->quantiteHS = $quantiteHS;

        return $this;
    }

    public function incrementerHS(int $delta = 1): self
    {
        $this->quantiteHS = max(0, $this->quantiteHS + $delta);

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

    /** Disponibilité dérivée = total − sortie − en affûtage − hors service (RG-PAT-06, CA-1). */
    #[Groups(['parc_patins:read'])]
    public function getQuantiteDisponible(): int
    {
        return max(0, $this->quantiteTotale - $this->quantiteSortie - $this->quantiteEnAffutage - $this->quantiteHS);
    }
}
