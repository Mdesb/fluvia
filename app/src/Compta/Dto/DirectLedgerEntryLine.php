<?php

declare(strict_types=1);

namespace App\Compta\Dto;

use App\Compta\Entity\CompteComptable;
use App\Compta\Entity\TauxTva;
use Symfony\Component\Uid\Uuid;

/**
 * Ligne d'écriture directe (§0.4 du plan), consommée par `App\Compta\Service\DirectLedgerEntryBuilder`.
 * Débit/crédit exclusifs (l'un des deux à 0), même contrainte applicative que le reste du module.
 */
final readonly class DirectLedgerEntryLine
{
    public function __construct(
        public CompteComptable $compte,
        public int $debitCentimes,
        public int $creditCentimes,
        public TauxTva $tauxTva,
        public ?string $libelle = null,
        public ?string $counterpartyType = null,
        public ?Uuid $counterpartyId = null,
        public ?string $counterpartyLabel = null,
    ) {
    }
}
