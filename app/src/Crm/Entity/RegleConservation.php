<?php

declare(strict_types=1);

namespace App\Crm\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Crm\Enum\ActionConservation;
use App\Organisation\Entity\Groupe;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/** Durée de conservation par catégorie de donnée (RG-M4-08). */
#[ORM\Entity]
#[ORM\Table(name: 'crm_regle_conservation')]
#[ApiResource(
    shortName: 'RegleConservation',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'crm.parametrer') or is_granted('PERM', 'crm.lire')"),
        new Get(security: "is_granted('PERM', 'crm.parametrer') or is_granted('PERM', 'crm.lire')"),
        new Post(security: "is_granted('PERM', 'crm.parametrer')"),
        new Patch(security: "is_granted('PERM', 'crm.parametrer')"),
    ],
    normalizationContext: ['groups' => ['conservation:read']],
    denormalizationContext: ['groups' => ['conservation:write']],
)]
class RegleConservation
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['conservation:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Groupe::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['conservation:read', 'conservation:write'])]
    private ?Groupe $groupe = null;

    #[ORM\Column(length: 64)]
    #[Assert\NotBlank]
    #[Groups(['conservation:read', 'conservation:write'])]
    private string $categorieDonnee = '';

    #[ORM\Column]
    #[Assert\Positive]
    #[Groups(['conservation:read', 'conservation:write'])]
    private int $dureeMois = 12;

    #[ORM\Column(length: 16, enumType: ActionConservation::class)]
    #[Groups(['conservation:read', 'conservation:write'])]
    private ActionConservation $actionEcheance = ActionConservation::Anonymisation;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
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

    public function getCategorieDonnee(): string
    {
        return $this->categorieDonnee;
    }

    public function setCategorieDonnee(string $categorieDonnee): self
    {
        $this->categorieDonnee = $categorieDonnee;

        return $this;
    }

    public function getDureeMois(): int
    {
        return $this->dureeMois;
    }

    public function setDureeMois(int $dureeMois): self
    {
        $this->dureeMois = $dureeMois;

        return $this;
    }

    public function getActionEcheance(): ActionConservation
    {
        return $this->actionEcheance;
    }

    public function setActionEcheance(ActionConservation $actionEcheance): self
    {
        $this->actionEcheance = $actionEcheance;

        return $this;
    }
}
