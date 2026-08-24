<?php

declare(strict_types=1);

namespace App\RevenueRecovery\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Organisation\Entity\Etablissement;
use App\RevenueRecovery\Enum\RecoveryTriggerType;
use App\RevenueRecovery\State\RecoverySequenceProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Politique de relance par établissement × type de déclencheur (plan-revenue-recovery.md §1, patron
 * `App\Recouvrement\Entity\PolitiqueRecouvrement` — répliqué, jamais importé, D2).
 *
 * `establishment` est l'ancre de cloisonnement (D6/D8, §0.6 du plan) — toujours dérivée côté serveur
 * (`ContexteEtablissement::etablissementActif()` dans `RecoverySequenceProcessor`), jamais d'un champ du
 * corps de la requête (absente du groupe d'écriture, même patron que `App\Ocr\Entity\OcrProviderConfig`).
 *
 * RG-RR-02 : `active` vaut `false` par défaut — contrairement à `PolitiqueRecouvrement`, aucune séquence
 * n'agit tant qu'un exploitant ne l'a pas explicitement activée.
 */
#[ORM\Entity]
#[ORM\Table(name: 'revenue_recovery_sequence')]
#[ORM\UniqueConstraint(name: 'uniq_recovery_sequence_establishment_trigger', columns: ['establishment_id', 'trigger_type'])]
#[ApiResource(
    shortName: 'RecoverySequence',
    operations: [
        new GetCollection(
            uriTemplate: '/revenue-recovery/sequences',
            security: "is_granted('PERM', 'revenue_recovery.read')",
        ),
        new Get(
            uriTemplate: '/revenue-recovery/sequences/{id}',
            security: "is_granted('PERM', 'revenue_recovery.read')",
        ),
        // RG-RR-01 : création par corps brut (pas d'{id}), hors du filet de
        // `RevenueRecoveryScopeExtension` — établissement + unicité revérifiés explicitement dans le
        // processor (§0.6 pt.2 du plan, piège D8).
        new Post(
            uriTemplate: '/revenue-recovery/sequences',
            security: "is_granted('PERM', 'revenue_recovery.configure')",
            processor: RecoverySequenceProcessor::class,
        ),
        new Patch(
            uriTemplate: '/revenue-recovery/sequences/{id}',
            security: "is_granted('PERM', 'revenue_recovery.configure')",
            processor: RecoverySequenceProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['recovery_sequence:read']],
    denormalizationContext: ['groups' => ['recovery_sequence:write']],
)]
#[ApiFilter(SearchFilter::class, properties: ['triggerType' => 'exact', 'active' => 'exact'])]
class RecoverySequence
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['recovery_sequence:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(name: 'establishment_id', nullable: false)]
    #[Groups(['recovery_sequence:read'])]
    private ?Etablissement $establishment = null;

    #[ORM\Column(name: 'trigger_type', length: 32, enumType: RecoveryTriggerType::class)]
    #[Assert\NotNull]
    #[Groups(['recovery_sequence:read', 'recovery_sequence:write'])]
    private RecoveryTriggerType $triggerType = RecoveryTriggerType::CartAbandoned;

    #[ORM\Column(options: ['default' => false])]
    #[Groups(['recovery_sequence:read', 'recovery_sequence:write'])]
    private bool $active = false;

    #[ORM\Column(name: 'max_attempts', type: 'smallint', options: ['default' => 3])]
    #[Assert\Positive]
    #[Groups(['recovery_sequence:read', 'recovery_sequence:write'])]
    private int $maxAttempts = 3;

    /**
     * Étapes ordonnées `{ delayDays: int, channel: 'email', templateCode: string }` (RG-RR-08, analogue
     * `PolitiqueRecouvrement::calendrierRepresentationJours`). `RecoveryEngine::handle()` programme au
     * plus `min(count(steps), maxAttempts)` tentatives, toutes calculées depuis `RecoveryCase.openedAt`
     * (« J+1, J+3 » de CA-1 US-RR-01 — délais comptés depuis le fait déclencheur, pas les uns des autres).
     *
     * @var list<array{delayDays: int, channel: string, templateCode: string}>
     */
    #[ORM\Column]
    #[Groups(['recovery_sequence:read', 'recovery_sequence:write'])]
    private array $steps = [];

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    #[Groups(['recovery_sequence:read'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    #[Groups(['recovery_sequence:read'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
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

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): self
    {
        $this->active = $active;

        return $this;
    }

    public function getMaxAttempts(): int
    {
        return $this->maxAttempts;
    }

    public function setMaxAttempts(int $maxAttempts): self
    {
        $this->maxAttempts = $maxAttempts;

        return $this;
    }

    /** @return list<array{delayDays: int, channel: string, templateCode: string}> */
    public function getSteps(): array
    {
        return $this->steps;
    }

    /** @param list<array{delayDays: int, channel: string, templateCode: string}> $steps */
    public function setSteps(array $steps): self
    {
        $this->steps = $steps;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touchUpdatedAt(): self
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }
}
