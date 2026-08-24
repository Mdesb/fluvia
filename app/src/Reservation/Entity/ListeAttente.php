<?php

declare(strict_types=1);

namespace App\Reservation\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Crm\Entity\Beneficiaire;
use App\Reservation\Enum\StatutListeAttente;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/** Inscription en liste d'attente sur un Créneau complet (RG-M5-06), promotion automatique. */
#[ORM\Entity]
#[ORM\Table(name: 'reservation_liste_attente')]
#[ORM\UniqueConstraint(name: 'uniq_liste_attente_creneau_rang', columns: ['creneau_id', 'rang'])]
#[ApiResource(
    shortName: 'ReservationListeAttente',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'reservation.lire') or is_granted('PERM', 'reservation.lire_soi')"),
        new Get(security: "is_granted('PERM', 'reservation.lire') or is_granted('PERM', 'reservation.lire_soi')"),
        // La création (POST) est déclarée sur `Creneau` (`/reservation/creneaux/{id}/liste-attente`) :
        // évite une variable d'URI secondaire non résolvable nativement par API Platform.
    ],
    normalizationContext: ['groups' => ['liste_attente:read']],
)]
#[ApiFilter(SearchFilter::class, properties: ['creneau' => 'exact', 'statut' => 'exact'])]
class ListeAttente
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['liste_attente:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Creneau::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['liste_attente:read'])]
    private ?Creneau $creneau = null;

    #[ORM\ManyToOne(targetEntity: Beneficiaire::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['liste_attente:read'])]
    private ?Beneficiaire $beneficiaire = null;

    #[ORM\Column(type: 'smallint')]
    #[Groups(['liste_attente:read'])]
    private int $rang = 1;

    /**
     * ACT-1 / D16 point 1 — une table de huit attend pour huit couverts. Sans cette quantité, la
     * promotion rendrait une place à un groupe qui en demande huit, et le créneau repasserait en
     * surréservation au premier désistement.
     */
    #[ORM\Column(options: ['default' => 1])]
    #[Assert\Positive(message: 'La quantité attendue doit être un entier strictement positif.')]
    #[Groups(['liste_attente:read'])]
    private int $quantity = 1;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['liste_attente:read'])]
    private \DateTimeImmutable $dateInscription;

    #[ORM\Column(length: 12, enumType: StatutListeAttente::class, options: ['default' => 'en_attente'])]
    #[Groups(['liste_attente:read'])]
    private StatutListeAttente $statut = StatutListeAttente::EnAttente;

    #[ORM\ManyToOne(targetEntity: Reservation::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['liste_attente:read'])]
    private ?Reservation $promueEn = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['liste_attente:read'])]
    private ?\DateTimeImmutable $dateExpirationPromotion = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->dateInscription = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCreneau(): ?Creneau
    {
        return $this->creneau;
    }

    public function setCreneau(?Creneau $creneau): self
    {
        $this->creneau = $creneau;

        return $this;
    }

    public function getBeneficiaire(): ?Beneficiaire
    {
        return $this->beneficiaire;
    }

    public function setBeneficiaire(?Beneficiaire $beneficiaire): self
    {
        $this->beneficiaire = $beneficiaire;

        return $this;
    }

    public function getRang(): int
    {
        return $this->rang;
    }

    public function setRang(int $rang): self
    {
        $this->rang = $rang;

        return $this;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function setQuantity(int $quantity): self
    {
        $this->quantity = $quantity;

        return $this;
    }

    public function getDateInscription(): \DateTimeImmutable
    {
        return $this->dateInscription;
    }

    public function getStatut(): StatutListeAttente
    {
        return $this->statut;
    }

    public function setStatut(StatutListeAttente $statut): self
    {
        $this->statut = $statut;

        return $this;
    }

    public function getPromueEn(): ?Reservation
    {
        return $this->promueEn;
    }

    public function setPromueEn(?Reservation $promueEn): self
    {
        $this->promueEn = $promueEn;

        return $this;
    }

    public function getDateExpirationPromotion(): ?\DateTimeImmutable
    {
        return $this->dateExpirationPromotion;
    }

    public function setDateExpirationPromotion(?\DateTimeImmutable $dateExpirationPromotion): self
    {
        $this->dateExpirationPromotion = $dateExpirationPromotion;

        return $this;
    }
}
