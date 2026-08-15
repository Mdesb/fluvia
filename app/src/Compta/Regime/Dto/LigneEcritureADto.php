<?php

declare(strict_types=1);

namespace App\Compta\Regime\Dto;

use App\Compta\Entity\CompteComptable;
use App\Compta\Entity\TauxTva;

/**
 * Ligne d'écriture proposée par un `RegimeComptableInterface`, avant persistance. Une seule des deux
 * valeurs debit/credit est non nulle (RG-M6-04).
 */
final class LigneEcritureADto
{
    public function __construct(
        public readonly CompteComptable $compte,
        public readonly int $debitCentimes,
        public readonly int $creditCentimes,
        public readonly TauxTva $tauxTva,
        public readonly ?string $axeSite = null,
        public readonly ?string $axeActivite = null,
        public readonly ?string $axeFinanceur = null,
        public readonly ?string $libelle = null,
    ) {
    }
}
