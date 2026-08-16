<?php

declare(strict_types=1);

namespace App\Musee\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use App\Reservation\Entity\Reservation;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use App\Musee\Enum\MotifGratuite;
use Symfony\Component\Uid\Uuid;

/**
 * Gratuité accordée (RG-MUS-03, CA-5) : décrémente **simultanément** le quota de jauge du créneau
 * (via `reservationRattachee`, `Reservation.modeDecompte=gratuit`, module socle Réservation
 * **réutilisé**) et le `ContingentGratuite` dédié. Lecture seule — dérivée de la confirmation du
 * dossier groupe/scolaire (`ConfirmerDossierGroupeHandler`/`AccorderGratuiteHandler`).
 */
#[ORM\Entity]
#[ORM\Table(name: 'musee_gratuite')]
#[ORM\UniqueConstraint(name: 'uniq_gratuite_reservation', columns: ['reservation_rattachee_id'])]
#[ApiResource(
    shortName: 'MuseeGratuite',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'musee.lire')"),
    ],
    normalizationContext: ['groups' => ['gratuite:read']],
)]
#[ApiFilter(SearchFilter::class, properties: ['dossier' => 'exact', 'contingent' => 'exact'])]
class Gratuite
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['gratuite:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: DossierGroupeScolaire::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['gratuite:read'])]
    private ?DossierGroupeScolaire $dossier = null;

    #[ORM\Column(length: 16, enumType: MotifGratuite::class)]
    #[Groups(['gratuite:read'])]
    private ?MotifGratuite $motif = null;

    #[ORM\ManyToOne(targetEntity: ContingentGratuite::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['gratuite:read'])]
    private ?ContingentGratuite $contingent = null;

    #[ORM\OneToOne(targetEntity: Reservation::class)]
    #[ORM\JoinColumn(nullable: false, unique: true)]
    #[Groups(['gratuite:read'])]
    private ?Reservation $reservationRattachee = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getDossier(): ?DossierGroupeScolaire
    {
        return $this->dossier;
    }

    public function setDossier(?DossierGroupeScolaire $dossier): self
    {
        $this->dossier = $dossier;

        return $this;
    }

    public function getMotif(): ?MotifGratuite
    {
        return $this->motif;
    }

    public function setMotif(?MotifGratuite $motif): self
    {
        $this->motif = $motif;

        return $this;
    }

    public function getContingent(): ?ContingentGratuite
    {
        return $this->contingent;
    }

    public function setContingent(?ContingentGratuite $contingent): self
    {
        $this->contingent = $contingent;

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
}
