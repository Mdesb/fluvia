<?php

declare(strict_types=1);

namespace App\Membership\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Acces\Entity\DroitAcces;
use App\Membership\Enum\MotifInactiviteAccesFitness;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use App\Membership\Entity\Membership;

/**
 * Projection Sport ↔ L3 (§0/§1.6 du plan, cœur du différenciateur RG-SPORT-04). Porte le **motif**
 * métier d'inactivité (non connu de L3, qui ne porte qu'un booléen valide/dévalidé via
 * `DroitAcces.statutProjection`). `droitAcces` est nullable jusqu'à l'appairage du support physique
 * (résolu via `POST /sport/abonnements/{id}/rattacher-droit-acces`).
 */
#[ORM\Entity]
#[ORM\Table(name: 'sport_statut_acces_fitness')]
#[ORM\UniqueConstraint(name: 'uniq_statut_acces_abonnement', columns: ['abonnement_id'])]
#[ApiResource(
    shortName: 'StatutAccesFitness',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'sport.lire')"),
        new Get(security: "is_granted('PERM', 'sport.lire')"),
    ],
    normalizationContext: ['groups' => ['statut_acces:read']],
)]
class StatutAccesFitness
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['statut_acces:read'])]
    private Uuid $id;

    #[ORM\OneToOne(targetEntity: Membership::class)]
    #[ORM\JoinColumn(name: 'abonnement_id', nullable: false)]
    #[Groups(['statut_acces:read'])]
    private ?Membership $abonnement = null;

    #[ORM\ManyToOne(targetEntity: DroitAcces::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['statut_acces:read'])]
    private ?DroitAcces $droitAcces = null;

    #[ORM\Column(options: ['default' => true])]
    #[Groups(['statut_acces:read'])]
    private bool $actif = true;

    #[ORM\Column(length: 12, nullable: true, enumType: MotifInactiviteAccesFitness::class)]
    #[Groups(['statut_acces:read'])]
    private ?MotifInactiviteAccesFitness $motifInactivite = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['statut_acces:read'])]
    private ?\DateTimeImmutable $dateDernierePropagation = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getAbonnement(): ?Membership
    {
        return $this->abonnement;
    }

    public function setAbonnement(?Membership $abonnement): self
    {
        $this->abonnement = $abonnement;

        return $this;
    }

    public function getDroitAcces(): ?DroitAcces
    {
        return $this->droitAcces;
    }

    public function setDroitAcces(?DroitAcces $droitAcces): self
    {
        $this->droitAcces = $droitAcces;

        return $this;
    }

    public function isActif(): bool
    {
        return $this->actif;
    }

    public function setActif(bool $actif): self
    {
        $this->actif = $actif;

        return $this;
    }

    public function getMotifInactivite(): ?MotifInactiviteAccesFitness
    {
        return $this->motifInactivite;
    }

    public function setMotifInactivite(?MotifInactiviteAccesFitness $motifInactivite): self
    {
        $this->motifInactivite = $motifInactivite;

        return $this;
    }

    public function getDateDernierePropagation(): ?\DateTimeImmutable
    {
        return $this->dateDernierePropagation;
    }

    public function setDateDernierePropagation(?\DateTimeImmutable $dateDernierePropagation): self
    {
        $this->dateDernierePropagation = $dateDernierePropagation;

        return $this;
    }
}
