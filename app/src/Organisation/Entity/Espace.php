<?php

declare(strict_types=1);

namespace App\Organisation\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Espace : subdivision d'un Établissement (bassin, piste, court...). Rattaché à un Établissement.
 */
#[ORM\Entity]
#[ORM\Table(name: 'org_espace')]
#[ApiResource(
    shortName: 'Espace',
    operations: [
        new GetCollection(security: "is_granted('IS_AUTHENTICATED_FULLY')"),
        new Get(security: "is_granted('IS_AUTHENTICATED_FULLY')"),
        new Post(security: "is_granted('PERM', 'organisation.gerer')"),
        new Patch(security: "is_granted('PERM', 'organisation.gerer')"),
        new Delete(security: "is_granted('PERM', 'organisation.gerer')"),
    ],
    normalizationContext: ['groups' => ['espace:read']],
    denormalizationContext: ['groups' => ['espace:write']],
)]
class Espace
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['espace:read'])]
    private Uuid $id;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank]
    #[Groups(['espace:read', 'espace:write'])]
    private string $nom = '';

    #[ORM\ManyToOne(targetEntity: Etablissement::class, inversedBy: 'espaces')]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['espace:read', 'espace:write'])]
    private ?Etablissement $etablissement = null;

    #[ORM\Column(length: 60)]
    #[Assert\NotBlank]
    #[Groups(['espace:read', 'espace:write'])]
    private string $type = '';

    public function __construct()
    {
        $this->id = Uuid::v4();
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

    public function getEtablissement(): ?Etablissement
    {
        return $this->etablissement;
    }

    public function setEtablissement(?Etablissement $etablissement): self
    {
        $this->etablissement = $etablissement;

        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): self
    {
        $this->type = $type;

        return $this;
    }
}
