<?php

declare(strict_types=1);

namespace App\Sport\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Sport\Enum\StatutPauseAbonnement;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Demande de pause (US-SPORT-02, RG-SPORT-05). `Refusee` si un impayé est en cours (décision actée).
 * La création passe par la sous-ressource `POST /sport/abonnements/{id}/pauses` (déclarée sur
 * `AbonnementFitness`, même raison que `Consentement`/M4 : éviter un `{id}` d'URI ambigu).
 */
#[ORM\Entity]
#[ORM\Table(name: 'sport_pause_abonnement')]
#[ApiResource(
    shortName: 'PauseAbonnement',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'sport.lire')"),
        new Get(security: "is_granted('PERM', 'sport.lire')"),
    ],
    normalizationContext: ['groups' => ['pause:read']],
)]
class PauseAbonnement
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['pause:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: AbonnementFitness::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['pause:read'])]
    private ?AbonnementFitness $abonnement = null;

    #[ORM\Column(type: 'date_immutable')]
    #[Groups(['pause:read'])]
    private \DateTimeImmutable $dateDebut;

    #[ORM\Column(type: 'date_immutable')]
    #[Groups(['pause:read'])]
    private \DateTimeImmutable $dateFin;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['pause:read'])]
    private ?string $motif = null;

    #[ORM\Column(length: 10, enumType: StatutPauseAbonnement::class, options: ['default' => 'active'])]
    #[Groups(['pause:read'])]
    private StatutPauseAbonnement $statut = StatutPauseAbonnement::Active;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getAbonnement(): ?AbonnementFitness
    {
        return $this->abonnement;
    }

    public function setAbonnement(?AbonnementFitness $abonnement): self
    {
        $this->abonnement = $abonnement;

        return $this;
    }

    public function getDateDebut(): \DateTimeImmutable
    {
        return $this->dateDebut;
    }

    public function setDateDebut(\DateTimeImmutable $dateDebut): self
    {
        $this->dateDebut = $dateDebut;

        return $this;
    }

    public function getDateFin(): \DateTimeImmutable
    {
        return $this->dateFin;
    }

    public function setDateFin(\DateTimeImmutable $dateFin): self
    {
        $this->dateFin = $dateFin;

        return $this;
    }

    public function getMotif(): ?string
    {
        return $this->motif;
    }

    public function setMotif(?string $motif): self
    {
        $this->motif = $motif;

        return $this;
    }

    public function getStatut(): StatutPauseAbonnement
    {
        return $this->statut;
    }

    public function setStatut(StatutPauseAbonnement $statut): self
    {
        $this->statut = $statut;

        return $this;
    }
}
