<?php

declare(strict_types=1);

namespace App\Offre\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Type de produit : pilote les facettes (onglets/champs visibles) d'un Produit (RG-M1-02).
 * Référentiel administré (pas d'écriture API en L1). La matrice de conversion (RG-M1-11) est
 * portée par la relation self M-N typesCompatibles.
 */
#[ORM\Entity]
#[ORM\Table(name: 'off_type_produit')]
#[ORM\UniqueConstraint(name: 'uniq_type_produit_code', columns: ['code'])]
#[ApiResource(
    shortName: 'TypeProduit',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'offre.lire')"),
        new Get(security: "is_granted('PERM', 'offre.lire')"),
    ],
    normalizationContext: ['groups' => ['type:read']],
)]
class TypeProduit
{
    /** Facettes reconnues : pilotent l'affichage des onglets et les entités liées optionnelles. */
    public const FACETTE_STOCK = 'stock';
    public const FACETTE_CONSOMMATEUR = 'consommateur';
    public const FACETTE_VISIBILITE = 'visibilite';
    public const FACETTE_CARNET = 'carnet';
    public const FACETTE_BILLET = 'billet';
    public const FACETTE_FORMULE = 'formule';
    public const FACETTE_ACCES = 'acces';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['type:read', 'produit:read'])]
    private Uuid $id;

    #[ORM\Column(length: 48)]
    #[Assert\NotBlank]
    #[Groups(['type:read', 'produit:read'])]
    private string $code = '';

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Groups(['type:read', 'produit:read'])]
    private string $libelle = '';

    /** @var list<string> Facettes ⊂ {stock,consommateur,visibilite,carnet,billet,formule,acces}. */
    #[ORM\Column]
    #[Groups(['type:read', 'produit:read'])]
    private array $facettes = [];

    /** @var Collection<int, TypeProduit> Types compatibles pour la conversion assistée (RG-M1-11). */
    #[ORM\ManyToMany(targetEntity: TypeProduit::class)]
    #[ORM\JoinTable(name: 'off_type_produit_compatible')]
    #[ORM\JoinColumn(name: 'source_id', referencedColumnName: 'id')]
    #[ORM\InverseJoinColumn(name: 'cible_id', referencedColumnName: 'id')]
    #[Groups(['type:read'])]
    private Collection $typesCompatibles;

    /** @var array<string, mixed> Valeurs par défaut héritées (marges compostage, TVA, durée…). */
    #[ORM\Column(nullable: true)]
    #[Groups(['type:read'])]
    private ?array $defauts = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->typesCompatibles = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
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

    public function getLibelle(): string
    {
        return $this->libelle;
    }

    public function setLibelle(string $libelle): self
    {
        $this->libelle = $libelle;

        return $this;
    }

    /** @return list<string> */
    public function getFacettes(): array
    {
        return $this->facettes;
    }

    /** @param list<string> $facettes */
    public function setFacettes(array $facettes): self
    {
        $this->facettes = array_values($facettes);

        return $this;
    }

    public function aFacette(string $facette): bool
    {
        return \in_array($facette, $this->facettes, true);
    }

    /** @return Collection<int, TypeProduit> */
    public function getTypesCompatibles(): Collection
    {
        return $this->typesCompatibles;
    }

    public function addTypeCompatible(TypeProduit $type): self
    {
        if (!$this->typesCompatibles->contains($type)) {
            $this->typesCompatibles->add($type);
        }

        return $this;
    }

    public function estCompatibleAvec(TypeProduit $type): bool
    {
        return $this->typesCompatibles->contains($type);
    }

    /** @return array<string, mixed>|null */
    public function getDefauts(): ?array
    {
        return $this->defauts;
    }

    /** @param array<string, mixed>|null $defauts */
    public function setDefauts(?array $defauts): self
    {
        $this->defauts = $defauts;

        return $this;
    }
}
