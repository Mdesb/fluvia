<?php

declare(strict_types=1);

namespace App\Finance\Treasury\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Finance\Treasury\State\TreasurySettingsProcessor;
use App\Organisation\Entity\Etablissement;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Réglages de trésorerie par établissement (lot FIN-4, admin — `finance.manage`) : un seul réglage par
 * établissement (contrainte unique), défaut applicatif si absent (mêmes valeurs que
 * {@see self::DEFAULT_UNMATCHED_ALERT_DELAY_DAYS}/{@see self::DEFAULT_MATCHING_WINDOW_DAYS}, §7 point
 * 12 du plan — non données par la spec, à confirmer avec le métier). `establishment` **direct**
 * (`RESOURCES_VIA_PROFIL` de FIN-2 non applicable — Treasury n'a pas de `businessProfile`, §0.2 point 2
 * du plan).
 */
#[ORM\Entity]
#[ORM\Table(name: 'finance_treasury_settings')]
#[ORM\UniqueConstraint(name: 'uniq_treasury_settings_establishment', columns: ['establishment_id'])]
#[ApiResource(
    shortName: 'TreasurySettings',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'finance.read')"),
        new Get(security: "is_granted('PERM', 'finance.read')"),
        new Post(
            security: "is_granted('PERM', 'finance.manage')",
            processor: TreasurySettingsProcessor::class,
        ),
        new Patch(
            security: "is_granted('PERM', 'finance.manage')",
            processor: TreasurySettingsProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['treasury_settings:read']],
    denormalizationContext: ['groups' => ['treasury_settings:write']],
)]
class TreasurySettings
{
    public const DEFAULT_UNMATCHED_ALERT_DELAY_DAYS = 15;
    public const DEFAULT_MATCHING_WINDOW_DAYS = 5;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['treasury_settings:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(name: 'establishment_id', nullable: false, unique: true)]
    #[Assert\NotNull]
    #[Groups(['treasury_settings:read', 'treasury_settings:write'])]
    private ?Etablissement $establishment = null;

    #[ORM\Column(name: 'unmatched_alert_delay_days', type: 'integer', options: ['default' => self::DEFAULT_UNMATCHED_ALERT_DELAY_DAYS])]
    // `PositiveOrZero` (pas `Positive`) : `0` est une valeur légitime (alerte immédiate / fenêtre de
    // correspondance désactivée), utilisée notamment par les tests d'idempotence du cron (§0.9 du plan).
    #[Assert\PositiveOrZero]
    #[Groups(['treasury_settings:read', 'treasury_settings:write'])]
    private int $unmatchedAlertDelayDays = self::DEFAULT_UNMATCHED_ALERT_DELAY_DAYS;

    #[ORM\Column(name: 'matching_window_days', type: 'integer', options: ['default' => self::DEFAULT_MATCHING_WINDOW_DAYS])]
    // `PositiveOrZero` (pas `Positive`) : `0` est une valeur légitime (alerte immédiate / fenêtre de
    // correspondance désactivée), utilisée notamment par les tests d'idempotence du cron (§0.9 du plan).
    #[Assert\PositiveOrZero]
    #[Groups(['treasury_settings:read', 'treasury_settings:write'])]
    private int $matchingWindowDays = self::DEFAULT_MATCHING_WINDOW_DAYS;

    public function __construct()
    {
        $this->id = Uuid::v4();
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

    public function getUnmatchedAlertDelayDays(): int
    {
        return $this->unmatchedAlertDelayDays;
    }

    public function setUnmatchedAlertDelayDays(int $unmatchedAlertDelayDays): self
    {
        $this->unmatchedAlertDelayDays = $unmatchedAlertDelayDays;

        return $this;
    }

    public function getMatchingWindowDays(): int
    {
        return $this->matchingWindowDays;
    }

    public function setMatchingWindowDays(int $matchingWindowDays): self
    {
        $this->matchingWindowDays = $matchingWindowDays;

        return $this;
    }
}
