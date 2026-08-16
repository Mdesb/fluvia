<?php

declare(strict_types=1);

namespace App\Reservation\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/** Indisponibilité ponctuelle d'une Ressource (congé, maintenance…), §4.1. */
#[ORM\Entity]
#[ORM\Table(name: 'reservation_indisponibilite')]
#[ApiResource(
    shortName: 'ReservationIndisponibilite',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'reservation.lire')"),
        new Get(security: "is_granted('PERM', 'reservation.lire')"),
        new Post(security: "is_granted('PERM', 'reservation.gerer_ressource')"),
        new Patch(security: "is_granted('PERM', 'reservation.gerer_ressource')"),
        new Delete(security: "is_granted('PERM', 'reservation.gerer_ressource')"),
    ],
    normalizationContext: ['groups' => ['indispo:read']],
    denormalizationContext: ['groups' => ['indispo:write']],
)]
#[ApiFilter(SearchFilter::class, properties: ['ressource' => 'exact'])]
class IndisponibiliteRessource
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['indispo:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Ressource::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['indispo:read', 'indispo:write'])]
    private ?Ressource $ressource = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['indispo:read', 'indispo:write'])]
    private \DateTimeImmutable $debut;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['indispo:read', 'indispo:write'])]
    private \DateTimeImmutable $fin;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['indispo:read', 'indispo:write'])]
    private ?string $motif = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->debut = new \DateTimeImmutable();
        $this->fin = new \DateTimeImmutable('+1 hour');
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getRessource(): ?Ressource
    {
        return $this->ressource;
    }

    public function setRessource(?Ressource $ressource): self
    {
        $this->ressource = $ressource;

        return $this;
    }

    public function getDebut(): \DateTimeImmutable
    {
        return $this->debut;
    }

    public function setDebut(\DateTimeImmutable $debut): self
    {
        $this->debut = $debut;

        return $this;
    }

    public function getFin(): \DateTimeImmutable
    {
        return $this->fin;
    }

    public function setFin(\DateTimeImmutable $fin): self
    {
        $this->fin = $fin;

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
}
