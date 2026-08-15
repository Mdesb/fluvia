<?php

declare(strict_types=1);

namespace App\Compta\Regime\Dto;

use App\Compta\Enum\NatureOperation;
use Symfony\Component\Uid\Uuid;

/**
 * Écriture proposée par un `RegimeComptableInterface`, avant persistance/scellement. La persistance,
 * l'équilibrage et le scellement NF525 sont communs (`GenerateurEcrituresHandler`).
 */
final class EcritureADto
{
    /**
     * @param list<LigneEcritureADto> $lignes
     */
    public function __construct(
        public readonly NatureOperation $nature,
        public readonly \DateTimeImmutable $dateEcriture,
        public readonly array $lignes,
        public readonly ?Uuid $venteOrigine = null,
        public readonly ?string $libelle = null,
    ) {
    }

    public function totalDebitCentimes(): int
    {
        return array_sum(array_map(static fn (LigneEcritureADto $l): int => $l->debitCentimes, $this->lignes));
    }

    public function totalCreditCentimes(): int
    {
        return array_sum(array_map(static fn (LigneEcritureADto $l): int => $l->creditCentimes, $this->lignes));
    }

    public function estEquilibree(): bool
    {
        return $this->totalDebitCentimes() === $this->totalCreditCentimes();
    }
}
