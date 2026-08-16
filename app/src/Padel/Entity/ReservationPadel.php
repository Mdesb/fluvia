<?php

declare(strict_types=1);

namespace App\Padel\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Padel\Enum\StatutPartieOuverte;
use App\Padel\Security\JoueurLieVoter;
use App\Padel\State\RejoindrePartieProcessor;
use App\Reservation\Entity\Reservation;
use App\Reservation\Entity\Ressource;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Overlay 1:1 sur `App\Reservation\Entity\Reservation` (décision structurante n°1/2 du plan) : ne
 * porte que les champs propres au padel (coach, matching de parties ouvertes). Le paiement partagé,
 * le statut de paiement par joueur et la solidarité organisateur restent portés par
 * `ParticipantReservation` (socle, non dupliqué).
 */
#[ORM\Entity]
#[ORM\Table(name: 'padel_reservation')]
#[ApiResource(
    shortName: 'PadelReservation',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'padel.lire') or is_granted('PERM', 'padel.partie_rejoindre_soi')"),
        new Get(security: "is_granted('PERM', 'padel.lire') or (is_granted('PERM', 'padel.lire_soi') and is_granted('" . JoueurLieVoter::ATTRIBUTE . "', object))"),
        // Rejoint une partie ouverte (RG-PADEL-03, CA-3) : {id} = identifiant de l'overlay ReservationPadel.
        new Post(
            uriTemplate: '/padel/parties-ouvertes/{id}/rejoindre',
            read: true,
            input: false,
            security: "is_granted('PERM', 'padel.partie_rejoindre_soi') or is_granted('PERM', 'padel.reserver')",
            processor: RejoindrePartieProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['reservation_padel:read']],
)]
#[ApiFilter(SearchFilter::class, properties: ['ouverte' => 'exact', 'statutPartie' => 'exact'])]
class ReservationPadel
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['reservation_padel:read'])]
    private Uuid $id;

    #[ORM\OneToOne(targetEntity: Reservation::class)]
    #[ORM\JoinColumn(nullable: false, unique: true)]
    #[Groups(['reservation_padel:read'])]
    private ?Reservation $reservation = null;

    #[ORM\ManyToOne(targetEntity: TerrainPadel::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['reservation_padel:read'])]
    private ?TerrainPadel $terrain = null;

    #[ORM\Column(options: ['default' => false])]
    #[Groups(['reservation_padel:read'])]
    private bool $avecCoach = false;

    #[ORM\ManyToOne(targetEntity: Ressource::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['reservation_padel:read'])]
    private ?Ressource $coachRessource = null;

    #[ORM\ManyToOne(targetEntity: Reservation::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['reservation_padel:read'])]
    private ?Reservation $reservationCoach = null;

    #[ORM\Column(options: ['default' => false])]
    #[Groups(['reservation_padel:read'])]
    private bool $ouverte = false;

    #[ORM\Column(type: 'smallint', nullable: true)]
    #[Groups(['reservation_padel:read'])]
    private ?int $niveauViseMin = null;

    #[ORM\Column(type: 'smallint', nullable: true)]
    #[Groups(['reservation_padel:read'])]
    private ?int $niveauViseMax = null;

    #[ORM\Column(length: 14, enumType: StatutPartieOuverte::class, nullable: true)]
    #[Groups(['reservation_padel:read'])]
    private ?StatutPartieOuverte $statutPartie = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
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

    public function getTerrain(): ?TerrainPadel
    {
        return $this->terrain;
    }

    public function setTerrain(?TerrainPadel $terrain): self
    {
        $this->terrain = $terrain;

        return $this;
    }

    public function isAvecCoach(): bool
    {
        return $this->avecCoach;
    }

    public function setAvecCoach(bool $avecCoach): self
    {
        $this->avecCoach = $avecCoach;

        return $this;
    }

    public function getCoachRessource(): ?Ressource
    {
        return $this->coachRessource;
    }

    public function setCoachRessource(?Ressource $coachRessource): self
    {
        $this->coachRessource = $coachRessource;

        return $this;
    }

    public function getReservationCoach(): ?Reservation
    {
        return $this->reservationCoach;
    }

    public function setReservationCoach(?Reservation $reservationCoach): self
    {
        $this->reservationCoach = $reservationCoach;

        return $this;
    }

    public function isOuverte(): bool
    {
        return $this->ouverte;
    }

    public function setOuverte(bool $ouverte): self
    {
        $this->ouverte = $ouverte;

        return $this;
    }

    public function getNiveauViseMin(): ?int
    {
        return $this->niveauViseMin;
    }

    public function setNiveauViseMin(?int $niveauViseMin): self
    {
        $this->niveauViseMin = $niveauViseMin;

        return $this;
    }

    public function getNiveauViseMax(): ?int
    {
        return $this->niveauViseMax;
    }

    public function setNiveauViseMax(?int $niveauViseMax): self
    {
        $this->niveauViseMax = $niveauViseMax;

        return $this;
    }

    public function getStatutPartie(): ?StatutPartieOuverte
    {
        return $this->statutPartie;
    }

    public function setStatutPartie(?StatutPartieOuverte $statutPartie): self
    {
        $this->statutPartie = $statutPartie;

        return $this;
    }

    /** Nombre de places restantes (dérivé, non persisté — évite une désynchronisation). */
    #[Groups(['reservation_padel:read'])]
    public function getPlacesRestantes(): int
    {
        $occupees = $this->reservation?->getParticipants()->count() ?? 0;

        return max(0, 4 - $occupees);
    }
}
