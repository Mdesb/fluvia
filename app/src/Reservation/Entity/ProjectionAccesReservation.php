<?php

declare(strict_types=1);

namespace App\Reservation\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Organisation\Entity\Etablissement;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Projection optionnelle d'un droit d'accès sur la fenêtre du créneau (RG-M5-12). Créée en
 * side-effect à la confirmation d'une réservation sur une Ressource `ouvreAcces=true`
 * (`ProjectionAccesReservationHandler`).
 *
 * ⚠ CETTE FICHE A DIT PENDANT UN TEMPS « no-op documenté (log) en attendant » — c'était vrai avant
 * que le handler soit écrit, ça ne l'est plus : il CONSTRUIT un `App\Acces\Entity\DroitAcces` réel
 * (`sourceType = TypeDroitAcces::Booking`), avec révocation symétrique. Corrigé le 04/09 après
 * qu'une session ait failli en tirer une conclusion fausse sur ce qu'un réservant obtient.
 *
 * ⚠ CE QUI RESTE VRAI, ET QUI EST LA VRAIE LIMITE : rien n'est projeté si la Ressource ne porte pas
 * `ouvreAcces = true`. Mesuré le 04/09 en préproduction : **2 ressources sur 11** le portent. Une
 * réservation de visite guidée n'ouvre donc aucun accès — non par manque de code, mais par
 * configuration. `droitAccesRef` reste nullable pour cette raison.
 */
#[ORM\Entity]
#[ORM\Table(name: 'reservation_projection_acces')]
#[ORM\UniqueConstraint(name: 'uniq_projection_acces_reservation', columns: ['reservation_id'])]
#[ApiResource(
    shortName: 'ReservationProjectionAcces',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'reservation.lire')"),
        new Get(security: "is_granted('PERM', 'reservation.lire')"),
    ],
    normalizationContext: ['groups' => ['projection_acces:read']],
)]
#[ApiFilter(SearchFilter::class, properties: ['reservation' => 'exact'])]
class ProjectionAccesReservation
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['projection_acces:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Reservation::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['projection_acces:read'])]
    private ?Reservation $reservation = null;

    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    #[Groups(['projection_acces:read'])]
    private ?Uuid $droitAccesRef = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['projection_acces:read'])]
    private \DateTimeImmutable $fenetreDebut;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['projection_acces:read'])]
    private \DateTimeImmutable $fenetreFin;

    #[ORM\Column(type: 'integer', nullable: true)]
    #[Groups(['projection_acces:read'])]
    private ?int $margeAvanceMinutes = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    #[Groups(['projection_acces:read'])]
    private ?int $margeRetardMinutes = null;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['projection_acces:read'])]
    private ?Etablissement $etablissement = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->fenetreDebut = new \DateTimeImmutable();
        $this->fenetreFin = new \DateTimeImmutable('+1 hour');
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

    public function getDroitAccesRef(): ?Uuid
    {
        return $this->droitAccesRef;
    }

    public function setDroitAccesRef(?Uuid $droitAccesRef): self
    {
        $this->droitAccesRef = $droitAccesRef;

        return $this;
    }

    public function getFenetreDebut(): \DateTimeImmutable
    {
        return $this->fenetreDebut;
    }

    public function setFenetreDebut(\DateTimeImmutable $fenetreDebut): self
    {
        $this->fenetreDebut = $fenetreDebut;

        return $this;
    }

    public function getFenetreFin(): \DateTimeImmutable
    {
        return $this->fenetreFin;
    }

    public function setFenetreFin(\DateTimeImmutable $fenetreFin): self
    {
        $this->fenetreFin = $fenetreFin;

        return $this;
    }

    public function getMargeAvanceMinutes(): ?int
    {
        return $this->margeAvanceMinutes;
    }

    public function setMargeAvanceMinutes(?int $margeAvanceMinutes): self
    {
        $this->margeAvanceMinutes = $margeAvanceMinutes;

        return $this;
    }

    public function getMargeRetardMinutes(): ?int
    {
        return $this->margeRetardMinutes;
    }

    public function setMargeRetardMinutes(?int $margeRetardMinutes): self
    {
        $this->margeRetardMinutes = $margeRetardMinutes;

        return $this;
    }

    public function getEtablissement(): ?Etablissement
    {
        return $this->etablissement;
    }

    public function setEtablissement(?Etablissement $etablissement): self
    {
        $this->etablissement = $etablissement;

        return $this;
    }
}
