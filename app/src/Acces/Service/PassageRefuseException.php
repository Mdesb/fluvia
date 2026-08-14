<?php

declare(strict_types=1);

namespace App\Acces\Service;

use App\Acces\Enum\CodeMotifRefus;

/**
 * Marqueur interne (non exposé à l'API) : signale un refus survenant à l'intérieur de la transaction
 * atomique crédit+FMI (§4.3 étape 7-8). Levée pour déclencher le ROLLBACK SQL (annule le décompte de
 * crédit déjà effectué), puis interceptée pour construire le `Passage` refusé hors transaction.
 */
final class PassageRefuseException extends \RuntimeException
{
    public function __construct(
        public readonly CodeMotifRefus $codeMotif,
        string $motif,
    ) {
        parent::__construct($motif);
    }
}
