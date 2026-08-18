<?php

declare(strict_types=1);

namespace App\Autorisation\Exception;

use App\Autorisation\Service\Decision;

/**
 * Levée par un Processor M2 quand `ServiceAutorisation::evaluer()` répond ESCALADE_REQUISE (§6.3
 * plan) : interceptée par `EscaladeRequiseExceptionListener` pour produire le corps 403 attendu
 * (RG-AUTZ-06).
 */
final class EscaladeRequiseException extends \RuntimeException
{
    public function __construct(
        public readonly Decision $decision,
    ) {
        parent::__construct($decision->motif);
    }
}
