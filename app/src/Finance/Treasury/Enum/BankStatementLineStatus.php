<?php

declare(strict_types=1);

namespace App\Finance\Treasury\Enum;

/**
 * Cycle de vie d'une ligne de relevé bancaire (§1 du plan, RG-TRE-03/04, D5) : `unmatched` (défaut) ->
 * `suggested` (candidature unique posée par `finance:treasury:suggerer-rapprochements`, §0.7) ->
 * `reconciled` (confirmée, lettrée, §0.6) ; ou `ignored` (marquée manuellement, motif requis, §7 cas
 * limite spec).
 */
enum BankStatementLineStatus: string
{
    case Unmatched = 'unmatched';
    case Suggested = 'suggested';
    case Reconciled = 'reconciled';
    case Ignored = 'ignored';
}
