<?php

declare(strict_types=1);

namespace App\Reservation\Enum;

/**
 * Stratégie de facturation d'un no-show (RG-M5-09, ⚠ point ouvert §8 spec). Réellement branchées :
 * `VenteDiffereeAgent`, `DebitPmv`. Squelettes documentés (non garantis encaisser) :
 * `PrelevementDiffere`, `FactureAEncaisser`.
 */
enum ModeFacturationNoShow: string
{
    case VenteDiffereeAgent = 'vente_differee_agent';
    case DebitPmv = 'debit_pmv';
    case PrelevementDiffere = 'prelevement_differe';
    case FactureAEncaisser = 'facture_a_encaisser';
}
