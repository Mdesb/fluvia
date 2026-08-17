<?php

declare(strict_types=1);

namespace App\Support\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Support\State\CategorieSuppressionProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Catégorie d'aide (US-SUP-03, RG-SUP-01) : arbre (parent optionnel), fil d'Ariane calculé en
 * lecture (`getFilAriane()`). Globale uniquement en v1 (§1.1 plan-support.md, ⚠ hypothèse §4.1
 * spec). Suppression bloquée si contient au moins un article ou une sous-catégorie
 * (`CategorieSuppressionProcessor`, 409 Conflict).
 */
#[ORM\Entity]
#[ORM\Table(name: 'support_categorie_aide')]
#[ApiResource(
    shortName: 'CategorieAide',
    operations: [
        new GetCollection(security: "is_granted('PUBLIC_ACCESS')"),
        new Get(security: "is_granted('PUBLIC_ACCESS')"),
        new Post(security: "is_granted('PERM', 'support.gerer_categorie')", denormalizationContext: ['groups' => ['categorie:write']]),
        new Patch(security: "is_granted('PERM', 'support.gerer_categorie')", denormalizationContext: ['groups' => ['categorie:write']]),
        new Post(
            uriTemplate: '/support/categories/{id}/supprimer',
            read: true,
            input: false,
            security: "is_granted('PERM', 'support.gerer_categorie')",
            processor: CategorieSuppressionProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['categorie:read']],
)]
class CategorieAide
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['categorie:read'])]
    private Uuid $id;

    #[ORM\Column(length: 150)]
    #[Assert\NotBlank]
    #[Groups(['categorie:read', 'categorie:write'])]
    private string $nom = '';

    #[ORM\Column(length: 160, unique: true)]
    #[Groups(['categorie:read'])]
    private string $slug = '';

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'parent_id', nullable: true)]
    #[Groups(['categorie:read', 'categorie:write'])]
    private ?self $parent = null;

    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    #[Groups(['categorie:read', 'categorie:write'])]
    private int $ordre = 0;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['categorie:read'])]
    private \DateTimeImmutable $dateCreation;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->dateCreation = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getNom(): string
    {
        return $this->nom;
    }

    public function setNom(string $nom): self
    {
        $this->nom = $nom;
        // Slug dérivé du nom, immuable une fois défini une première fois (§1.1 plan).
        if ($this->slug === '') {
            $this->slug = (new AsciiSlugger())->slug($nom)->lower()->toString();
        }

        return $this;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    /** Réservé à la résolution import (résolution/création par slug, §5.2 plan) : ne modifie jamais un slug existant en dehors de la création. */
    public function setSlug(string $slug): self
    {
        if ($this->slug === '') {
            $this->slug = $slug;
        }

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

    public function getOrdre(): int
    {
        return $this->ordre;
    }

    public function setOrdre(int $ordre): self
    {
        $this->ordre = $ordre;

        return $this;
    }

    public function getDateCreation(): \DateTimeImmutable
    {
        return $this->dateCreation;
    }

    /**
     * Fil d'Ariane complet, racine → … → catégorie courante (RG-SUP-01, US-SUP-03).
     *
     * @return list<array{id: string, nom: string, slug: string}>
     */
    #[Groups(['categorie:read'])]
    public function getFilAriane(): array
    {
        $chemin = [];
        $courant = $this;
        $garde = 0;
        while ($courant !== null && $garde < 50) {
            array_unshift($chemin, ['id' => (string) $courant->getId(), 'nom' => $courant->getNom(), 'slug' => $courant->getSlug()]);
            $courant = $courant->getParent();
            ++$garde;
        }

        return $chemin;
    }
}
