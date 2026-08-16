<?php

declare(strict_types=1);

namespace App\Padel\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Padel\Enum\ModeRepliEclairage;
use App\Padel\Enum\StatutRelaisEclairage;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Relais d'éclairage d'un terrain (1:1), US-PADEL-10, RG-PADEL-05 — capacité nouvelle, non couverte
 * par L3 (qui pilote des lecteurs/tourniquets, pas des actionneurs).
 */
#[ORM\Entity]
#[ORM\Table(name: 'padel_relais_eclairage')]
#[ApiResource(
    shortName: 'PadelRelaisEclairage',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'padel.lire')"),
        new Get(security: "is_granted('PERM', 'padel.lire')"),
        new Post(security: "is_granted('PERM', 'padel.configurer_eclairage')"),
        new Patch(security: "is_granted('PERM', 'padel.configurer_eclairage')"),
    ],
    normalizationContext: ['groups' => ['relais:read']],
    denormalizationContext: ['groups' => ['relais:write']],
)]
class RelaisEclairageTerrain
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['relais:read'])]
    private Uuid $id;

    #[ORM\OneToOne(targetEntity: TerrainPadel::class)]
    #[ORM\JoinColumn(nullable: false, unique: true)]
    #[Assert\NotNull]
    #[Groups(['relais:read', 'relais:write'])]
    private ?TerrainPadel $terrain = null;

    #[ORM\Column(length: 80)]
    #[Assert\NotBlank]
    #[Groups(['relais:read', 'relais:write'])]
    private string $identifiantRelais = '';

    #[ORM\Column(length: 6, enumType: ModeRepliEclairage::class, options: ['default' => 'manuel'])]
    #[Groups(['relais:read', 'relais:write'])]
    private ModeRepliEclairage $modeRepli = ModeRepliEclairage::Manuel;

    #[ORM\Column(length: 12, enumType: StatutRelaisEclairage::class, options: ['default' => 'operationnel'])]
    #[Groups(['relais:read'])]
    private StatutRelaisEclairage $statut = StatutRelaisEclairage::Operationnel;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTerrain(): ?TerrainPadel
    {
        return $this->terrain;
    }

    public function setTerrain(?TerrainPadel $terrain): self
    {
        $this->terrain = $terrain;

        return $this;
    }

    public function getIdentifiantRelais(): string
    {
        return $this->identifiantRelais;
    }

    public function setIdentifiantRelais(string $identifiantRelais): self
    {
        $this->identifiantRelais = $identifiantRelais;

        return $this;
    }

    public function getModeRepli(): ModeRepliEclairage
    {
        return $this->modeRepli;
    }

    public function setModeRepli(ModeRepliEclairage $modeRepli): self
    {
        $this->modeRepli = $modeRepli;

        return $this;
    }

    public function getStatut(): StatutRelaisEclairage
    {
        return $this->statut;
    }

    public function setStatut(StatutRelaisEclairage $statut): self
    {
        $this->statut = $statut;

        return $this;
    }

    public function getEtablissement(): ?\App\Organisation\Entity\Etablissement
    {
        return $this->terrain?->getRessource()?->getEtablissement();
    }
}
