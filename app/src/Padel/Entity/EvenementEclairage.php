<?php

declare(strict_types=1);

namespace App\Padel\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Padel\Enum\ActionEclairage;
use App\Padel\Enum\StatutEvenementEclairage;
use App\Reservation\Entity\Reservation;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/** Événement d'éclairage tracé (append-only), §4.9, RG-PADEL-05. */
#[ORM\Entity]
#[ORM\Table(name: 'padel_evenement_eclairage')]
#[ORM\Index(columns: ['terrain_id', 'horodatage'], name: 'idx_evenement_eclairage_terrain_horodatage')]
#[ApiResource(
    shortName: 'PadelEvenementEclairage',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'padel.lire')"),
        new Get(security: "is_granted('PERM', 'padel.lire')"),
    ],
    normalizationContext: ['groups' => ['evenement_eclairage:read']],
)]
#[ApiFilter(SearchFilter::class, properties: ['terrain' => 'exact', 'statut' => 'exact'])]
class EvenementEclairage
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['evenement_eclairage:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: TerrainPadel::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['evenement_eclairage:read'])]
    private ?TerrainPadel $terrain = null;

    #[ORM\ManyToOne(targetEntity: Reservation::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['evenement_eclairage:read'])]
    private ?Reservation $reservation = null;

    #[ORM\Column(length: 10, enumType: ActionEclairage::class)]
    #[Groups(['evenement_eclairage:read'])]
    private ActionEclairage $action = ActionEclairage::Allumage;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['evenement_eclairage:read'])]
    private \DateTimeImmutable $horodatage;

    #[ORM\Column(length: 20, enumType: StatutEvenementEclairage::class)]
    #[Groups(['evenement_eclairage:read'])]
    private StatutEvenementEclairage $statut = StatutEvenementEclairage::Ok;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['evenement_eclairage:read'])]
    private ?Utilisateur $operateur = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['evenement_eclairage:read'])]
    private ?string $motif = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->horodatage = new \DateTimeImmutable();
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

    public function getReservation(): ?Reservation
    {
        return $this->reservation;
    }

    public function setReservation(?Reservation $reservation): self
    {
        $this->reservation = $reservation;

        return $this;
    }

    public function getAction(): ActionEclairage
    {
        return $this->action;
    }

    public function setAction(ActionEclairage $action): self
    {
        $this->action = $action;

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

    public function getStatut(): StatutEvenementEclairage
    {
        return $this->statut;
    }

    public function setStatut(StatutEvenementEclairage $statut): self
    {
        $this->statut = $statut;

        return $this;
    }

    public function getOperateur(): ?Utilisateur
    {
        return $this->operateur;
    }

    public function setOperateur(?Utilisateur $operateur): self
    {
        $this->operateur = $operateur;

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
