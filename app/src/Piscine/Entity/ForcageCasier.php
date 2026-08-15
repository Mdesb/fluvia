<?php

declare(strict_types=1);

namespace App\Piscine\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/** Forçage administratif d'un casier non rendu (US-L6-09), journalisé (agent, motif, horodatage). */
#[ORM\Entity]
#[ORM\Table(name: 'piscine_forcage_casier')]
#[ApiResource(
    shortName: 'ForcageCasier',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'piscine.lire')"),
        new Get(security: "is_granted('PERM', 'piscine.lire')"),
    ],
    normalizationContext: ['groups' => ['forcage:read']],
)]
class ForcageCasier
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['forcage:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Casier::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['forcage:read'])]
    private ?Casier $casier = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['forcage:read'])]
    private ?Utilisateur $agent = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Groups(['forcage:read'])]
    private string $motif = '';

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['forcage:read'])]
    private \DateTimeImmutable $horodatage;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->horodatage = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCasier(): ?Casier
    {
        return $this->casier;
    }

    public function setCasier(?Casier $casier): self
    {
        $this->casier = $casier;

        return $this;
    }

    public function getAgent(): ?Utilisateur
    {
        return $this->agent;
    }

    public function setAgent(?Utilisateur $agent): self
    {
        $this->agent = $agent;

        return $this;
    }

    public function getMotif(): string
    {
        return $this->motif;
    }

    public function setMotif(string $motif): self
    {
        $this->motif = $motif;

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
}
