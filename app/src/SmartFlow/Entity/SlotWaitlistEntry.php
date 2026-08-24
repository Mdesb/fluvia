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
use App\SmartFlow\Enum\SlotWaitlistEntryStatus;
use App\SmartFlow\State\CreateSlotWaitlistEntryProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Liste d'attente Smart Flow (RG-SF-05..07, plan-smart-flow.md §1) — **distincte** de
 * `App\Reservation\Entity\ListeAttente` (interne, couplée à la jauge d'un créneau précis, RG-M5-06) :
 * une file **par ressource**, que Smart Flow peut promouvoir même quand aucun créneau précis n'est
 * encore identifié. Promue FIFO (`rank` croissant) à réception de `slot.released`
 * (`App\SmartFlow\EventListener\SlotReleasedListener`, RG-SF-06).
 *
 * `establishment` est l'ancre de cloisonnement (D6/D8) — résolue **serveur** via
 * `App\Securite\Service\ContexteEtablissement` à la création (`CreateSlotWaitlistEntryProcessor`),
 * jamais du corps de la requête (RG-SF-15). Aucune relation Doctrine vers `App\Reservation`/`App\Crm`
 * (RG-SF-17) : `resourceId`/`beneficiaryId`/`promotedProposalRef` sont des colonnes UUID opaques,
 * revérifiées à la volée (`ReservationSlotReader::resourceExists()`) quand nécessaire.
 */
#[ORM\Entity]
#[ORM\Table(name: 'smart_flow_slot_waitlist_entry')]
#[ORM\Index(columns: ['resource_id', 'status'], name: 'idx_slot_waitlist_resource_status')]
#[ApiResource(
    shortName: 'SlotWaitlistEntry',
    operations: [
        new GetCollection(
            uriTemplate: '/smart-flow/waitlist-entries',
            security: "is_granted('PERM', 'smart_flow.read')",
        ),
        new Get(
            uriTemplate: '/smart-flow/waitlist-entries/{id}',
            security: "is_granted('PERM', 'smart_flow.read')",
        ),
        // plan-smart-flow.md §2/§3 point 4 : `read: false` (création par corps brut, pas d'`{id}` dans
        // l'URI) — hors du filet de `App\SmartFlow\Doctrine\SmartFlowScopeExtension`, d'où la
        // revérification explicite d'établissement/ressource dans le processor lui-même (RG-SF-15).
        new Post(
            uriTemplate: '/smart-flow/waitlist-entries',
            read: false,
            input: false,
            security: "is_granted('PERM', 'smart_flow.reschedule_manage')",
            processor: CreateSlotWaitlistEntryProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['slot_waitlist_entry:read']],
)]
#[ApiFilter(SearchFilter::class, properties: ['resourceId' => 'exact', 'status' => 'exact'])]
class SlotWaitlistEntry
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['slot_waitlist_entry:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(name: 'establishment_id', nullable: false)]
    #[Groups(['slot_waitlist_entry:read'])]
    private ?Etablissement $establishment = null;

    #[ORM\Column(name: 'resource_id', type: UuidType::NAME)]
    #[Groups(['slot_waitlist_entry:read'])]
    private Uuid $resourceId;

    #[ORM\Column(name: 'beneficiary_id', type: UuidType::NAME)]
    #[Groups(['slot_waitlist_entry:read'])]
    private Uuid $beneficiaryId;

    #[ORM\Column(name: 'search_window_start', type: 'datetime_immutable')]
    #[Groups(['slot_waitlist_entry:read'])]
    private \DateTimeImmutable $searchWindowStart;

    #[ORM\Column(name: 'search_window_end', type: 'datetime_immutable')]
    #[Groups(['slot_waitlist_entry:read'])]
    private \DateTimeImmutable $searchWindowEnd;

    #[ORM\Column(type: 'smallint')]
    #[Groups(['slot_waitlist_entry:read'])]
    private int $rank = 0;

    #[ORM\Column(length: 10, enumType: SlotWaitlistEntryStatus::class, options: ['default' => 'waiting'])]
    #[Groups(['slot_waitlist_entry:read'])]
    private SlotWaitlistEntryStatus $status = SlotWaitlistEntryStatus::Waiting;

    #[ORM\Column(name: 'promoted_proposal_ref', type: UuidType::NAME, nullable: true)]
    #[Groups(['slot_waitlist_entry:read'])]
    private ?Uuid $promotedProposalRef = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    #[Groups(['slot_waitlist_entry:read'])]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->createdAt = new \DateTimeImmutable();
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

    public function getResourceId(): Uuid
    {
        return $this->resourceId;
    }

    public function setResourceId(Uuid $resourceId): self
    {
        $this->resourceId = $resourceId;

        return $this;
    }

    public function getBeneficiaryId(): Uuid
    {
        return $this->beneficiaryId;
    }

    public function setBeneficiaryId(Uuid $beneficiaryId): self
    {
        $this->beneficiaryId = $beneficiaryId;

        return $this;
    }

    public function getSearchWindowStart(): \DateTimeImmutable
    {
        return $this->searchWindowStart;
    }

    public function setSearchWindowStart(\DateTimeImmutable $searchWindowStart): self
    {
        $this->searchWindowStart = $searchWindowStart;

        return $this;
    }

    public function getSearchWindowEnd(): \DateTimeImmutable
    {
        return $this->searchWindowEnd;
    }

    public function setSearchWindowEnd(\DateTimeImmutable $searchWindowEnd): self
    {
        $this->searchWindowEnd = $searchWindowEnd;

        return $this;
    }

    public function getRank(): int
    {
        return $this->rank;
    }

    public function setRank(int $rank): self
    {
        $this->rank = $rank;

        return $this;
    }

    public function getStatus(): SlotWaitlistEntryStatus
    {
        return $this->status;
    }

    public function setStatus(SlotWaitlistEntryStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getPromotedProposalRef(): ?Uuid
    {
        return $this->promotedProposalRef;
    }

    public function setPromotedProposalRef(?Uuid $promotedProposalRef): self
    {
        $this->promotedProposalRef = $promotedProposalRef;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
