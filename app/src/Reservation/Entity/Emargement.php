<?php

declare(strict_types=1);

namespace App\Reservation\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Reservation\Enum\StatutEmargement;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/** Émargement manuel présent/absent (cahier M5-05), horodaté, modifiable jusqu'à clôture du créneau. */
#[ORM\Entity]
#[ORM\Table(name: 'reservation_emargement')]
#[ApiResource(
    shortName: 'ReservationEmargement',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'reservation.lire')"),
        new Get(security: "is_granted('PERM', 'reservation.lire')"),
        // La création (POST) est déclarée sur `Reservation` (`/reservation/reservations/{id}/emarger`) :
        // évite une variable d'URI secondaire non résolvable nativement par API Platform.
    ],
    normalizationContext: ['groups' => ['emargement:read']],
)]
#[ApiFilter(SearchFilter::class, properties: ['reservation' => 'exact'])]
class Emargement
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['emargement:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Reservation::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['emargement:read'])]
    private ?Reservation $reservation = null;

    #[ORM\Column(length: 8, enumType: StatutEmargement::class)]
    #[Groups(['emargement:read'])]
    private StatutEmargement $statut = StatutEmargement::Present;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['emargement:read'])]
    private \DateTimeImmutable $horodatage;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['emargement:read'])]
    private ?Utilisateur $operateur = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['emargement:read'])]
    private ?string $compteRendu = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->horodatage = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
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

    public function getStatut(): StatutEmargement
    {
        return $this->statut;
    }

    public function setStatut(StatutEmargement $statut): self
    {
        $this->statut = $statut;

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

    public function getOperateur(): ?Utilisateur
    {
        return $this->operateur;
    }

    public function setOperateur(?Utilisateur $operateur): self
    {
        $this->operateur = $operateur;

        return $this;
    }

    public function getCompteRendu(): ?string
    {
        return $this->compteRendu;
    }

    public function setCompteRendu(?string $compteRendu): self
    {
        $this->compteRendu = $compteRendu;

        return $this;
    }
}
