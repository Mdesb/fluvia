<?php

declare(strict_types=1);

namespace App\Autorisation\Service;

use App\Autorisation\Entity\DemandeEscalade;
use App\Autorisation\Entity\LimiteAutorisation;
use App\Autorisation\Enum\ResultatDecision;

/**
 * Valeur de retour de `ServiceAutorisation::evaluer()` (§1 plan, non persistée).
 */
final readonly class Decision
{
    public function __construct(
        public ResultatDecision $resultat,
        public string $motif,
        public ?LimiteAutorisation $limiteAppliquee = null,
        public ?DemandeEscalade $demandeEscalade = null,
    ) {
    }
}
