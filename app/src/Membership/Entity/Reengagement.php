<?php

declare(strict_types=1);

namespace App\Membership\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Sepa\Entity\MandatSepa;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use App\Membership\Entity\Membership;

/**
 * Traçabilité d'un réengagement (US-SPORT-04, décision actée). Créé par `ReengagementHandler` via la
 * sous-ressource `POST /sport/abonnements/{id}/reengager` (déclarée sur `Membership`).
 */
#[ORM\Entity]
#[ORM\Table(name: 'sport_reengagement')]
#[ORM\UniqueConstraint(name: 'uniq_reengagement_nouvel_abonnement', columns: ['nouvel_abonnement_id'])]
#[ApiResource(
    shortName: 'Reengagement',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'sport.lire')"),
        new Get(security: "is_granted('PERM', 'sport.lire')"),
    ],
    normalizationContext: ['groups' => ['reengagement:read']],
)]
class Reengagement
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['reengagement:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Membership::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['reengagement:read'])]
    private ?Membership $ancienAbonnement = null;

    #[ORM\OneToOne(targetEntity: Membership::class)]
    #[ORM\JoinColumn(name: 'nouvel_abonnement_id', nullable: false)]
    #[Groups(['reengagement:read'])]
    private ?Membership $nouvelAbonnement = null;

    #[ORM\ManyToOne(targetEntity: MandatSepa::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['reengagement:read'])]
    private ?MandatSepa $nouveauMandat = null;

    #[ORM\Column(type: 'date_immutable')]
    #[Groups(['reengagement:read'])]
    private \DateTimeImmutable $dateReengagement;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getAncienAbonnement(): ?Membership
    {
        return $this->ancienAbonnement;
    }

    public function setAncienAbonnement(?Membership $ancienAbonnement): self
    {
        $this->ancienAbonnement = $ancienAbonnement;

        return $this;
    }

    public function getNouvelAbonnement(): ?Membership
    {
        return $this->nouvelAbonnement;
    }

    public function setNouvelAbonnement(?Membership $nouvelAbonnement): self
    {
        $this->nouvelAbonnement = $nouvelAbonnement;

        return $this;
    }

    public function getNouveauMandat(): ?MandatSepa
    {
        return $this->nouveauMandat;
    }

    public function setNouveauMandat(?MandatSepa $nouveauMandat): self
    {
        $this->nouveauMandat = $nouveauMandat;

        return $this;
    }

    public function getDateReengagement(): \DateTimeImmutable
    {
        return $this->dateReengagement;
    }

    public function setDateReengagement(\DateTimeImmutable $dateReengagement): self
    {
        $this->dateReengagement = $dateReengagement;

        return $this;
    }
}
