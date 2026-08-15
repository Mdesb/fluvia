<?php

declare(strict_types=1);

namespace App\Vente\Port;

/**
 * Résultat d'une tentative de débit PMV (§2.1 plan-crm.md). Jamais d'exception métier générique :
 * un refus (solde insuffisant, PMV expiré/inexistant) est traduit par `PaiementHandler` en 422.
 */
final class ResultatDebitPmv
{
    public function __construct(
        public readonly bool $reussi,
        public readonly ?string $motifRefus = null,
    ) {
    }
}
