<?php

declare(strict_types=1);

namespace App\Finance\Treasury\Dto;

/**
 * Candidat de rapprochement (§0.7 du plan, RG-TRE-03, **non persisté**) — sortie de
 * `BankReconciliationSuggestionCalculator::candidats()` et de `GET …/statement-lines/{id}/suggestions`.
 */
final class ReconciliationCandidate
{
    public function __construct(
        public readonly string $ledgerLineId,
        public readonly string $ecritureId,
        public readonly string $date,
        public readonly int $amountCents,
        public readonly string $label,
        public readonly float $textScore,
    ) {
    }

    /** @return array{ledgerLineId: string, ecritureId: string, date: string, amountCents: int, label: string, textScore: float} */
    public function toArray(): array
    {
        return [
            'ledgerLineId' => $this->ledgerLineId,
            'ecritureId' => $this->ecritureId,
            'date' => $this->date,
            'amountCents' => $this->amountCents,
            'label' => $this->label,
            'textScore' => $this->textScore,
        ];
    }
}
