<?php

declare(strict_types=1);

namespace App\Piscine\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Piscine\Enum\ModeProrata;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Jauge grand public calculée au prorata des lignes club/scolaire (US-L6-07). Dérivée, recalculée
 * en temps réel par `PossProrataCalculator` à chaque écriture de `CreneauPublic` (CA-7). Lecture
 * seule côté API.
 */
#[ORM\Entity]
#[ORM\Table(name: 'piscine_jauge_grand_public_calculee')]
#[ORM\UniqueConstraint(name: 'uniq_jauge_gp_creneau_bassin', columns: ['creneau_bassin_id'])]
#[ApiResource(
    shortName: 'JaugeGrandPublicCalculee',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'piscine.lire')"),
        new Get(security: "is_granted('PERM', 'piscine.lire')"),
    ],
    normalizationContext: ['groups' => ['jauge_public:read']],
)]
class JaugeGrandPublicCalculee
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['jauge_public:read'])]
    private Uuid $id;

    #[ORM\OneToOne(targetEntity: CreneauBassin::class)]
    #[ORM\JoinColumn(name: 'creneau_bassin_id', nullable: false)]
    #[Groups(['jauge_public:read'])]
    private ?CreneauBassin $creneauBassin = null;

    #[ORM\Column]
    #[Groups(['jauge_public:read'])]
    private int $capaciteRestante = 0;

    #[ORM\Column(length: 10, enumType: ModeProrata::class, options: ['default' => 'lignes'])]
    #[Groups(['jauge_public:read'])]
    private ModeProrata $modeProrata = ModeProrata::Lignes;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['jauge_public:read'])]
    private \DateTimeImmutable $recalculeLe;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->recalculeLe = new \DateTimeImmutable();
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

    public function getCapaciteRestante(): int
    {
        return $this->capaciteRestante;
    }

    public function setCapaciteRestante(int $capaciteRestante): self
    {
        $this->capaciteRestante = max(0, $capaciteRestante);

        return $this;
    }

    public function getModeProrata(): ModeProrata
    {
        return $this->modeProrata;
    }

    public function setModeProrata(ModeProrata $modeProrata): self
    {
        $this->modeProrata = $modeProrata;

        return $this;
    }

    public function getRecalculeLe(): \DateTimeImmutable
    {
        return $this->recalculeLe;
    }

    public function setRecalculeLe(\DateTimeImmutable $recalculeLe): self
    {
        $this->recalculeLe = $recalculeLe;

        return $this;
    }
}
