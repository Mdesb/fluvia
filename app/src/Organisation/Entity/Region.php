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
 * Région rattachée à un Groupe (RG-SOCLE-01). Le lien vers le Groupe est obligatoire (CA-1).
 */
#[ORM\Entity]
#[ORM\Table(name: 'org_region')]
#[ApiResource(
    shortName: 'Region',
    operations: [
        new GetCollection(security: "is_granted('IS_AUTHENTICATED_FULLY')"),
        new Get(security: "is_granted('IS_AUTHENTICATED_FULLY')"),
        new Post(security: "is_granted('PERM', 'organisation.gerer')"),
        new Patch(security: "is_granted('PERM', 'organisation.gerer')"),
        new Delete(security: "is_granted('PERM', 'organisation.gerer')"),
    ],
    normalizationContext: ['groups' => ['region:read']],
    denormalizationContext: ['groups' => ['region:write']],
)]
class Region
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['region:read', 'etablissement:read'])]
    private Uuid $id;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank]
    #[Groups(['region:read', 'region:write', 'etablissement:read'])]
    private string $nom = '';

    #[ORM\ManyToOne(targetEntity: Groupe::class, inversedBy: 'regions')]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['region:read', 'region:write'])]
    private ?Groupe $groupe = null;

    /** @var Collection<int, Etablissement> */
    #[ORM\OneToMany(targetEntity: Etablissement::class, mappedBy: 'region')]
    private Collection $etablissements;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->etablissements = new ArrayCollection();
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

    public function getGroupe(): ?Groupe
    {
        return $this->groupe;
    }

    public function setGroupe(?Groupe $groupe): self
    {
        $this->groupe = $groupe;

        return $this;
    }

    /** @return Collection<int, Etablissement> */
    public function getEtablissements(): Collection
    {
        return $this->etablissements;
    }
}
