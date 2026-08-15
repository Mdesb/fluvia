<?php

declare(strict_types=1);

namespace App\Sport\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Acces\Entity\EspaceAcces;
use App\Sport\State\DetecterPresenceIsoleeProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Détection de présence isolée (US-SPORT-09, §4.8 spec) : dérivée de la jauge FMI nocturne (`JaugeFmi`
 * L3, lecture seule, `RG-ACC-04`). ⚠ HYPOTHÈSE comportement aval — simple signalement en supervision.
 */
#[ORM\Entity]
#[ORM\Table(name: 'sport_alerte_presence_isolee')]
#[ApiResource(
    shortName: 'AlertePresenceIsolee',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'sport.superviser_nocturne')"),
        new Get(security: "is_granted('PERM', 'sport.superviser_nocturne')"),
        new Post(
            uriTemplate: '/sport/espaces/{id}/detecter-presence-isolee',
            read: false,
            input: false,
            security: "is_granted('PERM', 'sport.superviser_nocturne') or is_granted('PERM', 'acces.superviser')",
            processor: DetecterPresenceIsoleeProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['alerte:read']],
)]
class AlertePresenceIsolee
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['alerte:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: EspaceAcces::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['alerte:read'])]
    private ?EspaceAcces $espaceAcces = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['alerte:read'])]
    private \DateTimeImmutable $horodatage;

    #[ORM\Column(type: 'smallint')]
    #[Groups(['alerte:read'])]
    private int $nbPersonnesDetectees = 1;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->horodatage = new \DateTimeImmutable();
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

    public function getHorodatage(): \DateTimeImmutable
    {
        return $this->horodatage;
    }

    public function setHorodatage(\DateTimeImmutable $horodatage): self
    {
        $this->horodatage = $horodatage;

        return $this;
    }

    public function getNbPersonnesDetectees(): int
    {
        return $this->nbPersonnesDetectees;
    }

    public function setNbPersonnesDetectees(int $nbPersonnesDetectees): self
    {
        $this->nbPersonnesDetectees = $nbPersonnesDetectees;

        return $this;
    }
}
