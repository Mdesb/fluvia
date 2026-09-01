<?php

declare(strict_types=1);

namespace App\Finance\Treasury\Enum;

/**
 * Cycle de vie d'une `TreasuryCashAlert` (addendum FIN-4, RG-TRE-13, D5) : `open` (créée à la première
 * détection d'un franchissement projeté, mise à jour à chaque passage tant qu'elle reste ouverte) ->
 * `resolved` (plus aucun franchissement projeté dans la fenêtre — résolution silencieuse, §4.4 de la
 * spec — ou désactivation du seuil sur `TreasurySettings`). Valeurs anglaises (D5).
 */
enum CashAlertStatus: string
{
    case Open = 'open';
    case Resolved = 'resolved';
}
