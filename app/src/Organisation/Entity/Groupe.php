<?php

declare(strict_types=1);

namespace App\Organisation\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Sommet de la hiérarchie multi-entités (RG-SOCLE-01) : Groupe › Région › Établissement › Espace.
 */
#[ORM\Entity]
#[ORM\Table(name: 'org_groupe')]
#[ApiResource(
    shortName: 'Groupe',
    operations: [
        new GetCollection(security: "is_granted('IS_AUTHENTICATED_FULLY')"),
        new Get(security: "is_granted('IS_AUTHENTICATED_FULLY')"),
        new Post(security: "is_granted('PERM', 'organisation.gerer')"),
        new Patch(security: "is_granted('PERM', 'organisation.gerer')"),
        new Delete(security: "is_granted('PERM', 'organisation.gerer')"),
    ],
    normalizationContext: ['groups' => ['groupe:read']],
    denormalizationContext: ['groups' => ['groupe:write']],
)]
class Groupe
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['groupe:read', 'region:read', 'etablissement:read'])]
    private Uuid $id;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank]
    #[Groups(['groupe:read', 'groupe:write', 'region:read'])]
    private string $nom = '';

    /** @var Collection<int, Region> */
    #[ORM\OneToMany(targetEntity: Region::class, mappedBy: 'groupe')]
    private Collection $regions;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->regions = new ArrayCollection();
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

        return $this;
    }

    /** @return Collection<int, Region> */
    public function getRegions(): Collection
    {
        return $this->regions;
    }
}
