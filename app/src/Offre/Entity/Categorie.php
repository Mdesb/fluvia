<?php

declare(strict_types=1);

namespace App\Offre\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Offre\Enum\AxeCategorie;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Catégorie d'un des trois axes indépendants (marketing / comptable / rayon) — RG-M1-05.
 * Un produit porte au plus une valeur par axe ; l'axe comptable est obligatoire pour publier.
 * Arborescence par axe via le parent (self-ref).
 */
#[ORM\Entity]
#[ORM\Table(name: 'off_categorie')]
#[ApiResource(
    shortName: 'Categorie',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'offre.lire')"),
        new Get(security: "is_granted('PERM', 'offre.lire')"),
        new Post(security: "is_granted('PERM', 'offre.gerer')"),
        new Patch(security: "is_granted('PERM', 'offre.gerer')"),
        new Delete(security: "is_granted('PERM', 'offre.gerer')"),
    ],
    normalizationContext: ['groups' => ['cat:read']],
    denormalizationContext: ['groups' => ['cat:write']],
)]
class Categorie
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['cat:read', 'produit:read'])]
    private Uuid $id;

    #[ORM\Column(length: 12, enumType: AxeCategorie::class)]
    #[Assert\NotNull]
    #[Groups(['cat:read', 'cat:write', 'produit:read'])]
    private ?AxeCategorie $axe = null;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Groups(['cat:read', 'cat:write', 'produit:read'])]
    private string $libelle = '';

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['cat:read', 'cat:write'])]
    private ?self $parent = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['cat:read'])]
    private ?string $chemin = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getAxe(): ?AxeCategorie
    {
        return $this->axe;
    }

    public function setAxe(?AxeCategorie $axe): self
    {
        $this->axe = $axe;

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

    public function getParent(): ?self
    {
        return $this->parent;
    }

    public function setParent(?self $parent): self
    {
        $this->parent = $parent;

        return $this;
    }

    public function getChemin(): ?string
    {
        return $this->chemin;
    }

    public function setChemin(?string $chemin): self
    {
        $this->chemin = $chemin;

        return $this;
    }
}
