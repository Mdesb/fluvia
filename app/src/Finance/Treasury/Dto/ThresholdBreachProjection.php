<?php

declare(strict_types=1);

namespace App\Finance\Treasury\Dto;

/**
 * Résultat de `ThresholdBreachProjectionCalculator::projeter()` (§0.4 du plan, RG-TRE-11/12) — non
 * persisté. `breachDate = null` signifie « aucun franchissement trouvé dans l'horizon » (§4.2 point 5
 * de la spec) : dans ce cas les quatre autres champs sont également `null`.
 */
final readonly class ThresholdBreachProjection
{
    public function __construct(
        public ?\DateTimeImmutable $breachDate,
        public ?int $balanceCentsAtBreach,
        public ?string $causeSource,
        public ?string $causeSourceId,
        public ?int $causeAmountCents,
    ) {
    }

    public static function aucunFranchissement(): self
    {
        return new self(null, null, null, null, null);
    }
}
