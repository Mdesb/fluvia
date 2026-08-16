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

/** Plage de disponibilité hebdomadaire d'une Ressource : borne la création de Créneaux (§4.1). */
#[ORM\Entity]
#[ORM\Table(name: 'reservation_disponibilite')]
#[ApiResource(
    shortName: 'ReservationDisponibilite',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'reservation.lire')"),
        new Get(security: "is_granted('PERM', 'reservation.lire')"),
        new Post(security: "is_granted('PERM', 'reservation.gerer_ressource')"),
        new Patch(security: "is_granted('PERM', 'reservation.gerer_ressource')"),
        new Delete(security: "is_granted('PERM', 'reservation.gerer_ressource')"),
    ],
    normalizationContext: ['groups' => ['dispo:read']],
    denormalizationContext: ['groups' => ['dispo:write']],
)]
#[ApiFilter(SearchFilter::class, properties: ['ressource' => 'exact'])]
class DisponibiliteRessource
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['dispo:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Ressource::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['dispo:read', 'dispo:write'])]
    private ?Ressource $ressource = null;

    #[ORM\Column(type: 'smallint')]
    #[Assert\Range(min: 1, max: 7)]
    #[Groups(['dispo:read', 'dispo:write'])]
    private int $jourSemaine = 1;

    #[ORM\Column(type: 'time_immutable')]
    #[Groups(['dispo:read', 'dispo:write'])]
    private \DateTimeImmutable $heureDebut;

    #[ORM\Column(type: 'time_immutable')]
    #[Groups(['dispo:read', 'dispo:write'])]
    private \DateTimeImmutable $heureFin;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->heureDebut = new \DateTimeImmutable('00:00');
        $this->heureFin = new \DateTimeImmutable('23:59');
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

    public function getJourSemaine(): int
    {
        return $this->jourSemaine;
    }

    public function setJourSemaine(int $jourSemaine): self
    {
        $this->jourSemaine = $jourSemaine;

        return $this;
    }

    public function getHeureDebut(): \DateTimeImmutable
    {
        return $this->heureDebut;
    }

    public function setHeureDebut(\DateTimeImmutable $heureDebut): self
    {
        $this->heureDebut = $heureDebut;

        return $this;
    }

    public function getHeureFin(): \DateTimeImmutable
    {
        return $this->heureFin;
    }

    public function setHeureFin(\DateTimeImmutable $heureFin): self
    {
        $this->heureFin = $heureFin;

        return $this;
    }
}
