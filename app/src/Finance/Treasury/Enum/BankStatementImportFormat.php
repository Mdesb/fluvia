<?php

declare(strict_types=1);

namespace App\Finance\Treasury\Enum;

/**
 * Format d'un import de relevé bancaire (§0.4 du plan, D5 — valeurs anglaises). `Csv` est le **seul**
 * format effectivement parsé par ce lot (`CsvBankStatementParser`) ; `Ofx`/`Camt053` sont déclarés
 * (contrat stable pour une extension future) mais renvoient 422 (« format non encore supporté »),
 * jamais 500. `Manual` (§0.4 — 4ᵉ valeur additive au-delà des 3 littéralement listées par
 * `spec-treasury.md` §5, à confirmer avec le propriétaire de la spec avant merge, §7 point 6 du plan) :
 * un import sans fichier, conteneur pour des `BankStatementLine` ajoutées une par une.
 */
enum BankStatementImportFormat: string
{
    case Csv = 'csv';
    case Ofx = 'ofx';
    case Camt053 = 'camt053';
    case Manual = 'manual';
}
