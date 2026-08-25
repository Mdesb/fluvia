<?php

declare(strict_types=1);

namespace App\RevenueRecovery\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use App\RevenueRecovery\Enum\RecoveryAttemptStatus;
use App\RevenueRecovery\Enum\RecoveryChannel;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Une tentative programmée/envoyée dans le cadre d'un `RecoveryCase` (plan-revenue-recovery.md §1,
 * patron `App\Recouvrement\Entity\RepresentationSepa` — répliqué, jamais importé, D2).
 *
 * Aucune écriture directe : produite uniquement par `App\RevenueRecovery\Service\RecoveryEngine`
 * (ouverture du dossier, programmation) et la tâche planifiée `revenue-recovery:attempts:send`
 * (exécution). Pas d'opération `Get`/`Post` exposée en I1 — lecture seule via `GetCollection`, filtrée
 * par établissement à travers `recoveryCase` (`RevenueRecoveryScopeExtension`, §0.6 pt.1 du plan).
 */
#[ORM\Entity]
#[ORM\Table(name: 'revenue_recovery_attempt')]
#[ORM\Index(columns: ['recovery_case_id'], name: 'idx_recovery_attempt_case')]
#[ORM\Index(columns: ['status', 'scheduled_at'], name: 'idx_recovery_attempt_scheduled')]
#[ApiResource(
    shortName: 'RecoveryAttempt',
    operations: [
        new GetCollection(
            uriTemplate: '/revenue-recovery/attempts',
            security: "is_granted('PERM', 'revenue_recovery.read')",
        ),
    ],
    normalizationContext: ['groups' => ['recovery_attempt:read']],
)]
#[ApiFilter(SearchFilter::class, properties: ['recoveryCase' => 'exact'])]
class RecoveryAttempt
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['recovery_attempt:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: RecoveryCase::class, inversedBy: 'attempts')]
    #[ORM\JoinColumn(name: 'recovery_case_id', nullable: false)]
    #[Groups(['recovery_attempt:read'])]
    private ?RecoveryCase $recoveryCase = null;

    #[ORM\Column(name: 'step_index', type: 'smallint')]
    #[Groups(['recovery_attempt:read'])]
    private int $stepIndex = 0;

    #[ORM\Column(name: 'scheduled_at', type: 'datetime_immutable')]
    #[Groups(['recovery_attempt:read'])]
    private \DateTimeImmutable $scheduledAt;

    #[ORM\Column(name: 'sent_at', type: 'datetime_immutable', nullable: true)]
    #[Groups(['recovery_attempt:read'])]
    private ?\DateTimeImmutable $sentAt = null;

    #[ORM\Column(length: 8, enumType: RecoveryChannel::class, options: ['default' => 'email'])]
    #[Groups(['recovery_attempt:read'])]
    private RecoveryChannel $channel = RecoveryChannel::Email;

    #[ORM\Column(length: 10, enumType: RecoveryAttemptStatus::class, options: ['default' => 'pending'])]
    #[Groups(['recovery_attempt:read'])]
    private RecoveryAttemptStatus $status = RecoveryAttemptStatus::Pending;

    /** Ex. `skipped_no_consent` (RG-RR-03) — code libre, pas une énumération fermée. */
    #[ORM\Column(name: 'skip_reason', length: 32, nullable: true)]
    #[Groups(['recovery_attempt:read'])]
    private ?string $skipReason = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->scheduledAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getRecoveryCase(): ?RecoveryCase
    {
        return $this->recoveryCase;
    }

    public function setRecoveryCase(?RecoveryCase $recoveryCase): self
    {
        $this->recoveryCase = $recoveryCase;

        return $this;
    }

    public function getStepIndex(): int
    {
        return $this->stepIndex;
    }

    public function setStepIndex(int $stepIndex): self
    {
        $this->stepIndex = $stepIndex;

        return $this;
    }

    public function getScheduledAt(): \DateTimeImmutable
    {
        return $this->scheduledAt;
    }

    public function setScheduledAt(\DateTimeImmutable $scheduledAt): self
    {
        $this->scheduledAt = $scheduledAt;

        return $this;
    }

    public function getSentAt(): ?\DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function setSentAt(?\DateTimeImmutable $sentAt): self
    {
        $this->sentAt = $sentAt;

        return $this;
    }

    public function getChannel(): RecoveryChannel
    {
        return $this->channel;
    }

    public function setChannel(RecoveryChannel $channel): self
    {
        $this->channel = $channel;

        return $this;
    }

    public function getStatus(): RecoveryAttemptStatus
    {
        return $this->status;
    }

    public function setStatus(RecoveryAttemptStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getSkipReason(): ?string
    {
        return $this->skipReason;
    }

    public function setSkipReason(?string $skipReason): self
    {
        $this->skipReason = $skipReason;

        return $this;
    }
}
