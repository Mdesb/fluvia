<?php

declare(strict_types=1);

namespace App\Piscine\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Organisation\Entity\Etablissement;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/** Affectation d'un encadrant qualifié à un créneau bassin (US-L6-04). */
#[ORM\Entity]
#[ORM\Table(name: 'piscine_affectation_encadrant')]
#[ApiResource(
    shortName: 'AffectationEncadrant',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'piscine.lire')"),
        new Get(security: "is_granted('PERM', 'piscine.lire')"),
        new Post(security: "is_granted('PERM', 'piscine.configurer')"),
        new Delete(security: "is_granted('PERM', 'piscine.configurer')"),
    ],
    normalizationContext: ['groups' => ['affect:read']],
    denormalizationContext: ['groups' => ['affect:write']],
)]
class AffectationEncadrant
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['affect:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: CreneauBassin::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['affect:read', 'affect:write'])]
    private ?CreneauBassin $creneauBassin = null;

    #[ORM\ManyToOne(targetEntity: QualificationEncadrant::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['affect:read', 'affect:write'])]
    private ?QualificationEncadrant $qualification = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCreneauBassin(): ?CreneauBassin
    {
        return $this->creneauBassin;
    }

    public function setCreneauBassin(?CreneauBassin $creneauBassin): self
    {
        $this->creneauBassin = $creneauBassin;

        return $this;
    }

    public function getQualification(): ?QualificationEncadrant
    {
        return $this->qualification;
    }

    public function setQualification(?QualificationEncadrant $qualification): self
    {
        $this->qualification = $qualification;

        return $this;
    }

    public function getEtablissement(): ?Etablissement
    {
        return $this->creneauBassin?->getEtablissement();
    }
}
