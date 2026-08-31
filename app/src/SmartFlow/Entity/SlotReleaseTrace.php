<?php

declare(strict_types=1);

namespace App\SmartFlow\Entity;

use App\Organisation\Entity\Etablissement;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Trace technique d'idempotence de `slot.released` (RG-SF-04, plan-smart-flow.md §0.4/§1).
 *
 * Avant de publier `slot.released`, `App\SmartFlow\EventListener\SlotFreedListener` tente un `INSERT` :
 * la contrainte `UNIQUE (slot_id, trigger_subject_id)` signale qu'une libération précise (un
 * `booking.cancelled`/`booking.no_show` donné, identifié par `subject.id` — la réservation à l'origine)
 * a déjà été traitée. Violation → arrêt silencieux, **pas** de republication (§0.4 du plan : la clé
 * `(slotId, causeEventId)` évoquée par la spec n'a pas de porteur technique littéral, `DomainEvent` ne
 * portant pas d'identifiant propre — remplacée par `(slotId, subject.type, subject.id)`).
 *
 * `released = false` documente le cas RG-SF-02/03 où la liste d'attente **interne** à
 * `App\Reservation` (`PromotionListeAttenteHandler`) avait déjà repris la place au moment où Smart Flow
 * relit la disponibilité réelle : la trace existe (le fait a été traité), mais aucun `slot.released`
 * n'a été publié.
 *
 * Pas de `#[ApiResource]` : table purement technique, jamais exposée en lecture (aucune ligne du plan
 * §2 ne la mentionne) — cloisonnée par `establishment` comme toute autre entité du module, mais sans
 * filtre `App\SmartFlow\Doctrine\SmartFlowScopeExtension` à câbler puisqu'aucune route ne la lit.
 */
#[ORM\Entity]
#[ORM\Table(name: 'smart_flow_slot_release_trace')]
#[ORM\UniqueConstraint(name: 'uniq_slot_release_trace_slot_trigger', columns: ['slot_id', 'trigger_subject_id'])]
class SlotReleaseTrace
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(name: 'establishment_id', nullable: false)]
    private ?Etablissement $establishment = null;

    #[ORM\Column(name: 'slot_id', type: UuidType::NAME)]
    private Uuid $slotId;

    #[ORM\Column(name: 'resource_id', type: UuidType::NAME)]
    private Uuid $resourceId;

    #[ORM\Column(name: 'trigger_event_name', length: 32)]
    private string $triggerEventName;

    #[ORM\Column(name: 'trigger_subject_id', type: UuidType::NAME)]
    private Uuid $triggerSubjectId;

    #[ORM\Column(options: ['default' => false])]
    private bool $released = false;

    #[ORM\Column(name: 'processed_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $processedAt;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->processedAt = new \DateTimeImmutable();
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

    public function getSlotId(): Uuid
    {
        return $this->slotId;
    }

    public function setSlotId(Uuid $slotId): self
    {
        $this->slotId = $slotId;

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

    public function getTriggerEventName(): string
    {
        return $this->triggerEventName;
    }

    public function setTriggerEventName(string $triggerEventName): self
    {
        $this->triggerEventName = $triggerEventName;

        return $this;
    }

    public function getTriggerSubjectId(): Uuid
    {
        return $this->triggerSubjectId;
    }

    public function setTriggerSubjectId(Uuid $triggerSubjectId): self
    {
        $this->triggerSubjectId = $triggerSubjectId;

        return $this;
    }

    public function isReleased(): bool
    {
        return $this->released;
    }

    public function setReleased(bool $released): self
    {
        $this->released = $released;

        return $this;
    }

    public function getProcessedAt(): \DateTimeImmutable
    {
        return $this->processedAt;
    }
}
