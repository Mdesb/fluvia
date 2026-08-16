<?php

declare(strict_types=1);

namespace App\Musee\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Acces\Entity\EspaceAcces;
use App\Organisation\Entity\Espace;
use App\Organisation\Entity\Etablissement;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Salle d'exposition (US-MUSEE-02, §4.2) : peut porter un point de contrôle mobile dédié via
 * `espaceAcces` (`App\Acces\Entity\EspaceAcces`, L3, **réutilisé, pas de copie**) — condition
 * nécessaire pour lui associer un `SousQuotaSalle`.
 */
#[ORM\Entity]
#[ORM\Table(name: 'musee_salle')]
#[ORM\UniqueConstraint(name: 'uniq_salle_espace_acces', columns: ['espace_acces_id'])]
#[ApiResource(
    shortName: 'MuseeSalle',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'musee.lire')"),
        new Get(security: "is_granted('PERM', 'musee.lire')"),
        new Post(security: "is_granted('PERM', 'musee.configurer')"),
        new Patch(security: "is_granted('PERM', 'musee.configurer')"),
    ],
    normalizationContext: ['groups' => ['salle:read']],
    denormalizationContext: ['groups' => ['salle:write']],
)]
#[ApiFilter(SearchFilter::class, properties: ['etablissement' => 'exact', 'exposition' => 'exact'])]
class Salle
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['salle:read', 'sousquota:read', 'delestage:read', 'salle_live:read'])]
    private Uuid $id;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Groups(['salle:read', 'salle:write', 'salle_live:read'])]
    private string $nom = '';

    #[ORM\ManyToOne(targetEntity: Espace::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['salle:read', 'salle:write'])]
    private ?Espace $espace = null;

    #[ORM\OneToOne(targetEntity: EspaceAcces::class)]
    #[ORM\JoinColumn(nullable: true, unique: true)]
    #[Groups(['salle:read', 'salle:write'])]
    private ?EspaceAcces $espaceAcces = null;

    #[ORM\ManyToOne(targetEntity: Exposition::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['salle:read', 'salle:write'])]
    private ?Exposition $exposition = null;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['salle:read'])]
    private ?Etablissement $etablissement = null;

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

    public function getEspace(): ?Espace
    {
        return $this->espace;
    }

    public function setEspace(?Espace $espace): self
    {
        $this->espace = $espace;
        if ($espace !== null) {
            $this->etablissement = $espace->getEtablissement();
        }

        return $this;
    }

    public function getEspaceAcces(): ?EspaceAcces
    {
        return $this->espaceAcces;
    }

    public function setEspaceAcces(?EspaceAcces $espaceAcces): self
    {
        $this->espaceAcces = $espaceAcces;

        return $this;
    }

    public function getExposition(): ?Exposition
    {
        return $this->exposition;
    }

    public function setExposition(?Exposition $exposition): self
    {
        $this->exposition = $exposition;

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
}
