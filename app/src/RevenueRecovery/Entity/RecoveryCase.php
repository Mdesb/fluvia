<?php

declare(strict_types=1);

namespace App\RevenueRecovery\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Organisation\Entity\Etablissement;
use App\RevenueRecovery\Enum\RecoveryCaseStatus;
use App\RevenueRecovery\Enum\RecoveryTriggerType;
use App\RevenueRecovery\State\StopRecoveryCaseProcessor;
use App\Securite\Entity\Utilisateur;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Dossier de relance ouvert par occurrence d'un déclencheur (plan-revenue-recovery.md §1, patron
 * `App\Recouvrement\Entity\IncidentImpaye` — répliqué, jamais importé, D2).
 *
 * `subjectType`/`subjectRef` sont dérivés **directement** de `DomainEvent->subject` (§0.8 du plan) —
 * opaques, aucune résolution vers l'entité métier d'origine (`Reservation`, `PaymentIncident`…) n'est
 * nécessaire pour ouvrir le dossier, et aucune association Doctrine ne les remplace. `amountCents` est
 * **nullable** : un panier abandonné, un devis expiré ou un no-show sans montant à risque connu n'ont
 * rien à chiffrer (spec §7).
 *
 * RG-RR-06 (invariant central, test dédié `RevenueRecoveryAccessInvariantTest`) : cette classe ne
 * référence jamais `App\Securite\Entity\DroitAcces` — `RevenueRecovery` n'écrit jamais sur l'accès.
 */
#[ORM\Entity]
#[ORM\Table(name: 'revenue_recovery_case')]
#[ORM\Index(columns: ['establishment_id', 'status'], name: 'idx_recovery_case_establishment_status')]
#[ORM\Index(columns: ['establishment_id', 'trigger_type', 'subject_type', 'subject_ref'], name: 'idx_recovery_case_subject')]
#[ApiResource(
    shortName: 'RecoveryCase',
    operations: [
        new GetCollection(
            uriTemplate: '/revenue-recovery/cases',
            security: "is_granted('PERM', 'revenue_recovery.read')",
        ),
        new Get(
            uriTemplate: '/revenue-recovery/cases/{id}',
            security: "is_granted('PERM', 'revenue_recovery.read')",
        ),
        // §2 du plan, piège D8 pt.1 : `read: true` (pas `read: false`) — la résolution de `{id}` passe
        // par le provider d'item standard, donc par `RevenueRecoveryScopeExtension` (échec fermé 404,
        // jamais 403, cohérent avec `plan-supplier-invoices.md` §0.2 pt.1 pour `/approve`).
        new Post(
            uriTemplate: '/revenue-recovery/cases/{id}/stop',
            read: true,
            input: false,
            security: "is_granted('PERM', 'revenue_recovery.manage')",
            processor: StopRecoveryCaseProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['recovery_case:read']],
)]
#[ApiFilter(SearchFilter::class, properties: ['status' => 'exact', 'triggerType' => 'exact'])]
class RecoveryCase
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['recovery_case:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(name: 'establishment_id', nullable: false)]
    #[Groups(['recovery_case:read'])]
    private ?Etablissement $establishment = null;

    #[ORM\Column(name: 'trigger_type', length: 32, enumType: RecoveryTriggerType::class)]
    #[Groups(['recovery_case:read'])]
    private RecoveryTriggerType $triggerType = RecoveryTriggerType::CartAbandoned;

    /** `EventSubject.type` d'origine, opaque (§0.8 du plan) — ex. « Reservation », « PaymentIncident ». */
    #[ORM\Column(name: 'subject_type', length: 32)]
    #[Groups(['recovery_case:read'])]
    private string $subjectType = '';

    /** `EventSubject.id` d'origine, opaque (§0.8 du plan). */
    #[ORM\Column(name: 'subject_ref', length: 64)]
    #[Groups(['recovery_case:read'])]
    private string $subjectRef = '';

    #[ORM\Column(name: 'amount_cents', nullable: true)]
    #[Groups(['recovery_case:read'])]
    private ?int $amountCents = null;

    #[ORM\Column(length: 16, enumType: RecoveryCaseStatus::class, options: ['default' => 'active'])]
    #[Groups(['recovery_case:read'])]
    private RecoveryCaseStatus $status = RecoveryCaseStatus::Active;

    /** Séquence appliquée à l'ouverture — figée : un changement ultérieur n'affecte pas ce dossier. */
    #[ORM\ManyToOne(targetEntity: RecoverySequence::class)]
    #[ORM\JoinColumn(name: 'sequence_id', nullable: false)]
    #[Groups(['recovery_case:read'])]
    private ?RecoverySequence $sequence = null;

    #[ORM\Column(name: 'opened_at', type: 'datetime_immutable')]
    #[Groups(['recovery_case:read'])]
    private \DateTimeImmutable $openedAt;

    #[ORM\Column(name: 'resolved_at', type: 'datetime_immutable', nullable: true)]
    #[Groups(['recovery_case:read'])]
    private ?\DateTimeImmutable $resolvedAt = null;

    #[ORM\Column(name: 'stopped_at', type: 'datetime_immutable', nullable: true)]
    #[Groups(['recovery_case:read'])]
    private ?\DateTimeImmutable $stoppedAt = null;

    /** Requis si `status = stopped` (RG-RR-05) — vérifié par `RecoveryEngine::stopManually()`, pas par une contrainte SQL. */
    #[ORM\Column(name: 'stop_reason', type: 'text', nullable: true)]
    #[Groups(['recovery_case:read'])]
    private ?string $stopReason = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(name: 'stopped_by_id', nullable: true)]
    #[Groups(['recovery_case:read'])]
    private ?Utilisateur $stoppedBy = null;

    /** @var Collection<int, RecoveryAttempt> */
    #[ORM\OneToMany(targetEntity: RecoveryAttempt::class, mappedBy: 'recoveryCase')]
    private Collection $attempts;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->openedAt = new \DateTimeImmutable();
        $this->attempts = new ArrayCollection();
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

    public function getTriggerType(): RecoveryTriggerType
    {
        return $this->triggerType;
    }

    public function setTriggerType(RecoveryTriggerType $triggerType): self
    {
        $this->triggerType = $triggerType;

        return $this;
    }

    public function getSubjectType(): string
    {
        return $this->subjectType;
    }

    public function setSubjectType(string $subjectType): self
    {
        $this->subjectType = $subjectType;

        return $this;
    }

    public function getSubjectRef(): string
    {
        return $this->subjectRef;
    }

    public function setSubjectRef(string $subjectRef): self
    {
        $this->subjectRef = $subjectRef;

        return $this;
    }

    public function getAmountCents(): ?int
    {
        return $this->amountCents;
    }

    public function setAmountCents(?int $amountCents): self
    {
        $this->amountCents = $amountCents;

        return $this;
    }

    public function getStatus(): RecoveryCaseStatus
    {
        return $this->status;
    }

    public function setStatus(RecoveryCaseStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getSequence(): ?RecoverySequence
    {
        return $this->sequence;
    }

    public function setSequence(?RecoverySequence $sequence): self
    {
        $this->sequence = $sequence;

        return $this;
    }

    public function getOpenedAt(): \DateTimeImmutable
    {
        return $this->openedAt;
    }

    public function setOpenedAt(\DateTimeImmutable $openedAt): self
    {
        $this->openedAt = $openedAt;

        return $this;
    }

    public function getResolvedAt(): ?\DateTimeImmutable
    {
        return $this->resolvedAt;
    }

    public function setResolvedAt(?\DateTimeImmutable $resolvedAt): self
    {
        $this->resolvedAt = $resolvedAt;

        return $this;
    }

    public function getStoppedAt(): ?\DateTimeImmutable
    {
        return $this->stoppedAt;
    }

    public function setStoppedAt(?\DateTimeImmutable $stoppedAt): self
    {
        $this->stoppedAt = $stoppedAt;

        return $this;
    }

    public function getStopReason(): ?string
    {
        return $this->stopReason;
    }

    public function setStopReason(?string $stopReason): self
    {
        $this->stopReason = $stopReason;

        return $this;
    }

    public function getStoppedBy(): ?Utilisateur
    {
        return $this->stoppedBy;
    }

    public function setStoppedBy(?Utilisateur $stoppedBy): self
    {
        $this->stoppedBy = $stoppedBy;

        return $this;
    }

    /** @return Collection<int, RecoveryAttempt> */
    public function getAttempts(): Collection
    {
        return $this->attempts;
    }

    /**
     * Maintient le côté inverse de l'association (indispensable : sans cela, après un `flush`, la
     * collection est « initialisée vide » et `RecoveryEngine::resolve()`/`stopManually()` n'itèrent
     * sur rien — les tentatives resteraient `Pending`).
     */
    public function addAttempt(RecoveryAttempt $attempt): self
    {
        if (!$this->attempts->contains($attempt)) {
            $this->attempts->add($attempt);
            $attempt->setRecoveryCase($this);
        }

        return $this;
    }
}
