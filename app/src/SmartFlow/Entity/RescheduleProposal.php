<?php

declare(strict_types=1);

namespace App\SmartFlow\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Organisation\Entity\Etablissement;
use App\SmartFlow\Enum\RescheduleProposalStatus;
use App\SmartFlow\State\AcceptRescheduleProposalProcessor;
use App\SmartFlow\State\DeclineRescheduleProposalProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Proposition de report d'un no-show restitué-avec-crédit (SF-2, RG-SF-08..12, plan-smart-flow.md §1).
 *
 * `establishment` est l'ancre de cloisonnement (D6/D8) — dérivée du `tenant` de l'événement
 * `booking.reschedule_requested` à la création (`RescheduleRequestedListener`), jamais d'un en-tête
 * HTTP (RG-SF-15). Aucune relation Doctrine vers `App\Reservation`/`App\Crm` (RG-SF-17) :
 * `originReservationRef`/`sourceWaitlistEntryRef`/`originSlotId`/`customerId`/`entitlementRef`/
 * `proposedSlotId`/`confirmedReservationRef` sont des colonnes UUID opaques, résolues à la volée par
 * `App\SmartFlow\Service\ReservationSlotReader` quand nécessaire — jamais persistées comme relation.
 *
 * `entitlementRef` (D5, anglais) correspond au `droitId` du payload `booking.reschedule_requested`
 * (traçabilité du crédit restitué à l'origine, RG-SF-12) — renommé ici pour respecter D5, aucun champ
 * du catalogue d'événements n'est modifié par ce renommage (le payload de l'événement reste `droitId`,
 * propriété d'`App\Reservation`, hors périmètre de ce lot).
 *
 * `originReservationRef` est **nullable** (correction au tableau §1 de la spec, plan §1 note de
 * conception) : une future promotion de liste d'attente Smart Flow (I2) réutilisera cette même entité
 * via `sourceWaitlistEntryRef` sans `originReservationRef`. En I1 (ce lot), seul
 * `RescheduleRequestedListener` crée des propositions et renseigne toujours `originReservationRef`.
 */
#[ORM\Entity]
#[ORM\Table(name: 'smart_flow_reschedule_proposal')]
#[ORM\Index(columns: ['establishment_id', 'status'], name: 'idx_reschedule_proposal_establishment_status')]
#[ORM\Index(columns: ['origin_slot_id'], name: 'idx_reschedule_proposal_origin_slot')]
#[ORM\Index(columns: ['customer_id'], name: 'idx_reschedule_proposal_customer')]
#[ORM\UniqueConstraint(name: 'uniq_reschedule_proposal_origin_reservation', columns: ['origin_reservation_ref'])]
#[ApiResource(
    shortName: 'RescheduleProposal',
    operations: [
        new GetCollection(
            uriTemplate: '/smart-flow/reschedule-proposals',
            security: "is_granted('PERM', 'smart_flow.reschedule_manage') or is_granted('PERM', 'smart_flow.reschedule_read_own')",
        ),
        new Get(
            uriTemplate: '/smart-flow/reschedule-proposals/{id}',
            security: "is_granted('PERM', 'smart_flow.reschedule_manage') or is_granted('PERM', 'smart_flow.reschedule_read_own')",
        ),
        // §0.9 du plan : ferme la proposition, ne crée jamais de réservation (Smart Flow n'écrit
        // jamais dans App\Reservation, D2/§2 de la spec). `read: true` : opère sur une ressource
        // existante identifiée par {id} (patron `AnnulerCreneauProcessor`/
        // `ArbitrerConflitRecurrenceProcessor` côté App\Reservation).
        new Post(
            uriTemplate: '/smart-flow/reschedule-proposals/{id}/accept',
            read: true,
            input: false,
            security: "is_granted('PERM', 'smart_flow.reschedule_manage') or is_granted('PERM', 'smart_flow.reschedule_read_own')",
            processor: AcceptRescheduleProposalProcessor::class,
        ),
        new Post(
            uriTemplate: '/smart-flow/reschedule-proposals/{id}/decline',
            read: true,
            input: false,
            security: "is_granted('PERM', 'smart_flow.reschedule_manage') or is_granted('PERM', 'smart_flow.reschedule_read_own')",
            processor: DeclineRescheduleProposalProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['reschedule_proposal:read']],
)]
#[ApiFilter(SearchFilter::class, properties: ['customerId' => 'exact', 'status' => 'exact'])]
class RescheduleProposal
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['reschedule_proposal:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(name: 'establishment_id', nullable: false)]
    #[Groups(['reschedule_proposal:read'])]
    private ?Etablissement $establishment = null;

    #[ORM\Column(name: 'origin_reservation_ref', type: UuidType::NAME, nullable: true)]
    #[Groups(['reschedule_proposal:read'])]
    private ?Uuid $originReservationRef = null;

    #[ORM\Column(name: 'source_waitlist_entry_ref', type: UuidType::NAME, nullable: true)]
    #[Groups(['reschedule_proposal:read'])]
    private ?Uuid $sourceWaitlistEntryRef = null;

    #[ORM\Column(name: 'origin_slot_id', type: UuidType::NAME)]
    #[Groups(['reschedule_proposal:read'])]
    private Uuid $originSlotId;

    #[ORM\Column(name: 'customer_id', type: UuidType::NAME)]
    #[Groups(['reschedule_proposal:read'])]
    private Uuid $customerId;

    #[ORM\Column(name: 'entitlement_id', type: UuidType::NAME)]
    #[Groups(['reschedule_proposal:read'])]
    private Uuid $entitlementRef;

    #[ORM\Column(length: 10, enumType: RescheduleProposalStatus::class, options: ['default' => 'searching'])]
    #[Groups(['reschedule_proposal:read'])]
    private RescheduleProposalStatus $status = RescheduleProposalStatus::Searching;

    #[ORM\Column(name: 'proposed_slot_id', type: UuidType::NAME, nullable: true)]
    #[Groups(['reschedule_proposal:read'])]
    private ?Uuid $proposedSlotId = null;

    #[ORM\Column(name: 'expires_at', type: 'datetime_immutable')]
    #[Groups(['reschedule_proposal:read'])]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(name: 'confirmed_reservation_ref', type: UuidType::NAME, nullable: true)]
    #[Groups(['reschedule_proposal:read'])]
    private ?Uuid $confirmedReservationRef = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    #[Groups(['reschedule_proposal:read'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'last_search_attempt_at', type: 'datetime_immutable', nullable: true)]
    #[Groups(['reschedule_proposal:read'])]
    private ?\DateTimeImmutable $lastSearchAttemptAt = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->createdAt = new \DateTimeImmutable();
        $this->expiresAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEstablishment(): ?Etablissement
    {
        return $this->establishment;
    }

    public function setEstablishment(?Etablissement $establishment): self
    {
        $this->establishment = $establishment;

        return $this;
    }

    public function getOriginReservationRef(): ?Uuid
    {
        return $this->originReservationRef;
    }

    public function setOriginReservationRef(?Uuid $originReservationRef): self
    {
        $this->originReservationRef = $originReservationRef;

        return $this;
    }

    public function getSourceWaitlistEntryRef(): ?Uuid
    {
        return $this->sourceWaitlistEntryRef;
    }

    public function setSourceWaitlistEntryRef(?Uuid $sourceWaitlistEntryRef): self
    {
        $this->sourceWaitlistEntryRef = $sourceWaitlistEntryRef;

        return $this;
    }

    public function getOriginSlotId(): Uuid
    {
        return $this->originSlotId;
    }

    public function setOriginSlotId(Uuid $originSlotId): self
    {
        $this->originSlotId = $originSlotId;

        return $this;
    }

    public function getCustomerId(): Uuid
    {
        return $this->customerId;
    }

    public function setCustomerId(Uuid $customerId): self
    {
        $this->customerId = $customerId;

        return $this;
    }

    public function getEntitlementRef(): Uuid
    {
        return $this->entitlementRef;
    }

    public function setEntitlementRef(Uuid $entitlementRef): self
    {
        $this->entitlementRef = $entitlementRef;

        return $this;
    }

    public function getStatus(): RescheduleProposalStatus
    {
        return $this->status;
    }

    public function setStatus(RescheduleProposalStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getProposedSlotId(): ?Uuid
    {
        return $this->proposedSlotId;
    }

    public function setProposedSlotId(?Uuid $proposedSlotId): self
    {
        $this->proposedSlotId = $proposedSlotId;

        return $this;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(\DateTimeImmutable $expiresAt): self
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }

    public function getConfirmedReservationRef(): ?Uuid
    {
        return $this->confirmedReservationRef;
    }

    public function setConfirmedReservationRef(?Uuid $confirmedReservationRef): self
    {
        $this->confirmedReservationRef = $confirmedReservationRef;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastSearchAttemptAt(): ?\DateTimeImmutable
    {
        return $this->lastSearchAttemptAt;
    }

    public function setLastSearchAttemptAt(?\DateTimeImmutable $lastSearchAttemptAt): self
    {
        $this->lastSearchAttemptAt = $lastSearchAttemptAt;

        return $this;
    }
}
