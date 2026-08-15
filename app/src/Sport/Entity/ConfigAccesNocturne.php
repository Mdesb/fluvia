<?php

declare(strict_types=1);

namespace App\Sport\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Acces\Entity\EspaceAcces;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Accès nocturne autonome sécurisé (US-SPORT-09, décision actée). Délègue l'espace à `EspaceAcces` L3
 * (existant, réutilisé — aucune duplication de topologie). `limiteOccupationNocturne` peut différer du
 * `seuilFmi` diurne de l'espace.
 */
#[ORM\Entity]
#[ORM\Table(name: 'sport_config_acces_nocturne')]
#[ORM\UniqueConstraint(name: 'uniq_config_nocturne_espace', columns: ['espace_acces_id'])]
#[ApiResource(
    shortName: 'ConfigAccesNocturne',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'sport.lire')"),
        new Get(security: "is_granted('PERM', 'sport.lire')"),
        new Post(security: "is_granted('PERM', 'sport.configurer_nocturne')"),
        new Patch(security: "is_granted('PERM', 'sport.configurer_nocturne')"),
    ],
    normalizationContext: ['groups' => ['nocturne:read']],
    denormalizationContext: ['groups' => ['nocturne:write']],
)]
class ConfigAccesNocturne
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['nocturne:read'])]
    private Uuid $id;

    #[ORM\OneToOne(targetEntity: EspaceAcces::class)]
    #[ORM\JoinColumn(name: 'espace_acces_id', nullable: false)]
    #[Assert\NotNull]
    #[Groups(['nocturne:read', 'nocturne:write'])]
    private ?EspaceAcces $espaceAcces = null;

    #[ORM\Column(length: 5)]
    #[Groups(['nocturne:read', 'nocturne:write'])]
    private string $plageDebut = '22:00';

    #[ORM\Column(length: 5)]
    #[Groups(['nocturne:read', 'nocturne:write'])]
    private string $plageFin = '06:00';

    #[ORM\Column(options: ['default' => true])]
    #[Groups(['nocturne:read', 'nocturne:write'])]
    private bool $videoActive = true;

    #[ORM\Column(options: ['default' => true])]
    #[Groups(['nocturne:read', 'nocturne:write'])]
    private bool $boutonSosActif = true;

    #[ORM\Column(options: ['default' => true])]
    #[Groups(['nocturne:read', 'nocturne:write'])]
    private bool $detectionPresenceIsoleeActive = true;

    #[ORM\Column(nullable: true)]
    #[Assert\PositiveOrZero]
    #[Groups(['nocturne:read', 'nocturne:write'])]
    private ?int $limiteOccupationNocturne = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
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

    public function getPlageDebut(): string
    {
        return $this->plageDebut;
    }

    public function setPlageDebut(string $plageDebut): self
    {
        $this->plageDebut = $plageDebut;

        return $this;
    }

    public function getPlageFin(): string
    {
        return $this->plageFin;
    }

    public function setPlageFin(string $plageFin): self
    {
        $this->plageFin = $plageFin;

        return $this;
    }

    public function isVideoActive(): bool
    {
        return $this->videoActive;
    }

    public function setVideoActive(bool $videoActive): self
    {
        $this->videoActive = $videoActive;

        return $this;
    }

    public function isBoutonSosActif(): bool
    {
        return $this->boutonSosActif;
    }

    public function setBoutonSosActif(bool $boutonSosActif): self
    {
        $this->boutonSosActif = $boutonSosActif;

        return $this;
    }

    public function isDetectionPresenceIsoleeActive(): bool
    {
        return $this->detectionPresenceIsoleeActive;
    }

    public function setDetectionPresenceIsoleeActive(bool $detectionPresenceIsoleeActive): self
    {
        $this->detectionPresenceIsoleeActive = $detectionPresenceIsoleeActive;

        return $this;
    }

    public function getLimiteOccupationNocturne(): ?int
    {
        return $this->limiteOccupationNocturne;
    }

    public function setLimiteOccupationNocturne(?int $limiteOccupationNocturne): self
    {
        $this->limiteOccupationNocturne = $limiteOccupationNocturne;

        return $this;
    }

    /** Vrai si l'horaire fourni tombe dans la plage nocturne (peut chevaucher minuit). */
    public function estDansPlageNocturne(\DateTimeImmutable $moment): bool
    {
        $heure = $moment->format('H:i');
        if ($this->plageDebut <= $this->plageFin) {
            return $heure >= $this->plageDebut && $heure < $this->plageFin;
        }

        return $heure >= $this->plageDebut || $heure < $this->plageFin;
    }
}
