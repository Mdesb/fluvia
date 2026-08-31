<?php

declare(strict_types=1);

namespace App\Musee\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Musee\Enum\StatutReservationOTA;
use App\Musee\State\CreerReservationOtaProcessor;
use App\Reservation\Entity\Reservation;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Vente OTA (US-MUSEE-08, RG-MUS-04) : **1 vente OTA = 1 `Reservation`** (module socle Réservation
 * **réutilisé**, décision structurante n°2 du plan) — décrémente le **même inventaire réel** que la
 * vente directe. `statutOta` porte une sémantique **additive** (conflit/récupération) — ne duplique
 * pas `Reservation.statut`.
 */
#[ORM\Entity]
#[ORM\Table(name: 'musee_reservation_ota')]
#[ORM\UniqueConstraint(name: 'uniq_resa_ota_reservation', columns: ['reservation_rattachee_id'])]
#[ApiResource(
    shortName: 'MuseeReservationOta',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'musee.lire')"),
        new Get(security: "is_granted('PERM', 'musee.lire')"),
        new Post(
            uriTemplate: '/musee/reservations-ota',
            security: "is_granted('PERM', 'musee.gerer_ota')",
            processor: CreerReservationOtaProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['resa_ota:read']],
)]
#[ApiFilter(SearchFilter::class, properties: ['allocation' => 'exact', 'statutOta' => 'exact'])]
class ReservationOTA
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['resa_ota:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: AllocationQuotaOTA::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['resa_ota:read'])]
    private ?AllocationQuotaOTA $allocation = null;

    #[ORM\OneToOne(targetEntity: Reservation::class)]
    #[ORM\JoinColumn(nullable: false, unique: true)]
    #[Groups(['resa_ota:read'])]
    private ?Reservation $reservationRattachee = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['resa_ota:read'])]
    private \DateTimeImmutable $horodatageConfirmation;

    #[ORM\Column(length: 20, enumType: StatutReservationOTA::class, options: ['default' => 'confirmee'])]
    #[Groups(['resa_ota:read'])]
    private StatutReservationOTA $statutOta = StatutReservationOTA::Confirmee;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->horodatageConfirmation = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getAllocation(): ?AllocationQuotaOTA
    {
        return $this->allocation;
    }

    public function setAllocation(?AllocationQuotaOTA $allocation): self
    {
        $this->allocation = $allocation;

        return $this;
    }

    public function getReservationRattachee(): ?Reservation
    {
        return $this->reservationRattachee;
    }

    public function setReservationRattachee(?Reservation $reservationRattachee): self
    {
        $this->reservationRattachee = $reservationRattachee;

        return $this;
    }

    public function getHorodatageConfirmation(): \DateTimeImmutable
    {
        return $this->horodatageConfirmation;
    }

    public function setHorodatageConfirmation(\DateTimeImmutable $horodatageConfirmation): self
    {
        $this->horodatageConfirmation = $horodatageConfirmation;

        return $this;
    }

    public function getStatutOta(): StatutReservationOTA
    {
        return $this->statutOta;
    }

    public function setStatutOta(StatutReservationOTA $statutOta): self
    {
        $this->statutOta = $statutOta;

        return $this;
    }
}
