<?php

declare(strict_types=1);

namespace App\Stay\Service;

use App\Stay\Entity\Stay;
use App\Stay\Enum\StayResolutionReason;

/**
 * Le résultat d'une tentative de rattachement d'une consommation à un séjour (ACT-3).
 *
 * Immuable, et volontairement plus riche qu'un `?Stay` : c'est ce qui permettra au futur abonné du
 * bus de journaliser une ambiguïté **sans interrompre la vente en cours**. Le bus est synchrone
 * (RG-PLAT-05) : une exception levée par un abonné remonte à l'émetteur. Un séjour introuvable ne doit
 * jamais empêcher un client de payer son verre.
 */
final class StayResolution
{
    private function __construct(
        public readonly ?Stay $stay,
        public readonly StayResolutionReason $reason,
    ) {
    }

    public static function matched(Stay $stay): self
    {
        return new self($stay, StayResolutionReason::Matched);
    }

    public static function noOpenStay(): self
    {
        return new self(null, StayResolutionReason::NoOpenStay);
    }

    public static function ambiguous(): self
    {
        return new self(null, StayResolutionReason::Ambiguous);
    }

    public function isMatched(): bool
    {
        return StayResolutionReason::Matched === $this->reason;
    }

    /** Vrai quand l'absence de rattachement mérite l'attention d'un exploitant. */
    public function needsAttention(): bool
    {
        return StayResolutionReason::Ambiguous === $this->reason;
    }
}
