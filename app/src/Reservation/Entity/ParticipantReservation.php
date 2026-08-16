<?php

declare(strict_types=1);

namespace App\Reservation\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Crm\Entity\Beneficiaire;
use App\Reservation\Enum\StatutPaiementParticipant;
use App\Reservation\State\PayerPartProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Part de paiement partagé (RG-M5-10). Chaque part est encaissée individuellement et tracée ;
 * l'organisateur devient solidaire du reste à payer en cas de défection d'un participant.
 */
#[ORM\Entity]
#[ORM\Table(name: 'reservation_participant')]
#[ORM\UniqueConstraint(name: 'uniq_participant_reservation_personne', columns: ['reservation_id', 'personne_id'])]
#[ApiResource(
    shortName: 'ReservationParticipant',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'reservation.lire')"),
        new Get(security: "is_granted('PERM', 'reservation.lire')"),
        // {id} = identifiant propre du participant (pas de variable d'URI secondaire, évite un
        // Link non résolvable nativement par API Platform).
        new Post(
            uriTemplate: '/reservation/participants/{id}/payer',
            read: true,
            input: false,
            security: "is_granted('PERM', 'reservation.reserver') or is_granted('PERM', 'reservation.reserver_soi')",
            processor: PayerPartProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['participant:read']],
)]
class ParticipantReservation
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['participant:read', 'reservation:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Reservation::class, inversedBy: 'participants')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['participant:read'])]
    private ?Reservation $reservation = null;

    #[ORM\ManyToOne(targetEntity: Beneficiaire::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['participant:read', 'reservation:read'])]
    private ?Beneficiaire $personne = null;

    #[ORM\Column(options: ['default' => false])]
    #[Groups(['participant:read', 'reservation:read'])]
    private bool $estOrganisateur = false;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    #[Groups(['participant:read', 'reservation:read'])]
    private string $partMontant = '0.00';

    #[ORM\Column(length: 20, enumType: StatutPaiementParticipant::class, options: ['default' => 'en_attente'])]
    #[Groups(['participant:read', 'reservation:read'])]
    private StatutPaiementParticipant $statutPaiement = StatutPaiementParticipant::EnAttente;

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

    public function getPersonne(): ?Beneficiaire
    {
        return $this->personne;
    }

    public function setPersonne(?Beneficiaire $personne): self
    {
        $this->personne = $personne;

        return $this;
    }

    public function isEstOrganisateur(): bool
    {
        return $this->estOrganisateur;
    }

    public function setEstOrganisateur(bool $estOrganisateur): self
    {
        $this->estOrganisateur = $estOrganisateur;

        return $this;
    }

    public function getPartMontant(): string
    {
        return $this->partMontant;
    }

    public function setPartMontant(string $partMontant): self
    {
        $this->partMontant = $partMontant;

        return $this;
    }

    public function getStatutPaiement(): StatutPaiementParticipant
    {
        return $this->statutPaiement;
    }

    public function setStatutPaiement(StatutPaiementParticipant $statutPaiement): self
    {
        $this->statutPaiement = $statutPaiement;

        return $this;
    }
}
