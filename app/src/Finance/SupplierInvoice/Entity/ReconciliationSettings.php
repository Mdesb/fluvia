<?php

declare(strict_types=1);

namespace App\Finance\SupplierInvoice\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Compta\Entity\ProfilExploitant;
use App\Finance\SupplierInvoice\State\ReconciliationSettingsProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Réglage de rapprochement 3 voies par profil exploitant (RG-SINV-03) — un seul réglage par profil
 * (contrainte unique), défaut applicatif `5.00` si absent (même patron que `ExpenseAccountMapping`,
 * FIN-1 : pas d'extension Doctrine dédiée, cloisonnement porté par le processor, §1 du plan).
 */
#[ORM\Entity]
#[ORM\Table(name: 'finance_reconciliation_settings')]
#[ORM\UniqueConstraint(name: 'uniq_reconciliation_settings_profil', columns: ['business_profile_id'])]
#[ApiResource(
    shortName: 'ReconciliationSettings',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'finance.read')"),
        new Get(security: "is_granted('PERM', 'finance.read')"),
        new Post(
            security: "is_granted('PERM', 'finance.manage')",
            processor: ReconciliationSettingsProcessor::class,
        ),
        new Patch(
            security: "is_granted('PERM', 'finance.manage')",
            processor: ReconciliationSettingsProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['reconciliation_settings:read']],
    denormalizationContext: ['groups' => ['reconciliation_settings:write']],
)]
class ReconciliationSettings
{
    /** Seuil d'écart par défaut (%) si aucun réglage n'existe pour le profil (RG-SINV-03). */
    public const DEFAULT_THRESHOLD_PERCENT = '5.00';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['reconciliation_settings:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: ProfilExploitant::class)]
    #[ORM\JoinColumn(name: 'business_profile_id', nullable: false)]
    #[Assert\NotNull]
    #[Groups(['reconciliation_settings:read', 'reconciliation_settings:write'])]
    private ?ProfilExploitant $businessProfile = null;

    #[ORM\Column(name: 'tolerance_threshold_percent', type: 'decimal', precision: 5, scale: 2, options: ['default' => self::DEFAULT_THRESHOLD_PERCENT])]
    #[Assert\PositiveOrZero]
    #[Groups(['reconciliation_settings:read', 'reconciliation_settings:write'])]
    private string $toleranceThresholdPercent = self::DEFAULT_THRESHOLD_PERCENT;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getBusinessProfile(): ?ProfilExploitant
    {
        return $this->businessProfile;
    }

    public function setBusinessProfile(?ProfilExploitant $businessProfile): self
    {
        $this->businessProfile = $businessProfile;

        return $this;
    }

    public function getToleranceThresholdPercent(): string
    {
        return $this->toleranceThresholdPercent;
    }

    public function setToleranceThresholdPercent(string $toleranceThresholdPercent): self
    {
        $this->toleranceThresholdPercent = $toleranceThresholdPercent;

        return $this;
    }
}
