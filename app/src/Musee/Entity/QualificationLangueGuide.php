<?php

declare(strict_types=1);

namespace App\Musee\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Qualification linguistique d'un guide (US-MUSEE-03/04) : filtre l'agenda par langue (§4.3) et
 * conditionne la confirmation d'une `VisiteGuidee` (RG-MUS-02). Référentiel administré par
 * l'Administrateur (§3 spec).
 */
#[ORM\Entity]
#[ORM\Table(name: 'musee_qualification_langue_guide')]
#[ORM\UniqueConstraint(name: 'uniq_qualif_guide_langue', columns: ['guide_id', 'langue'])]
#[ApiResource(
    shortName: 'MuseeQualificationLangueGuide',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'musee.lire')"),
        new Post(security: "is_granted('PERM', 'musee.gerer')"),
        new Delete(security: "is_granted('PERM', 'musee.gerer')"),
    ],
    normalizationContext: ['groups' => ['qualif:read']],
    denormalizationContext: ['groups' => ['qualif:write']],
)]
#[ApiFilter(SearchFilter::class, properties: ['guide' => 'exact', 'langue' => 'exact'])]
class QualificationLangueGuide
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['qualif:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Guide::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['qualif:read', 'qualif:write'])]
    private ?Guide $guide = null;

    #[ORM\Column(length: 8)]
    #[Assert\NotBlank]
    #[Groups(['qualif:read', 'qualif:write'])]
    private string $langue = '';

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getGuide(): ?Guide
    {
        return $this->guide;
    }

    public function setGuide(?Guide $guide): self
    {
        $this->guide = $guide;

        return $this;
    }

    public function getLangue(): string
    {
        return $this->langue;
    }

    public function setLangue(string $langue): self
    {
        $this->langue = $langue;

        return $this;
    }
}
