<?php

declare(strict_types=1);

namespace App\Acces\Enum;

/** Statut d'un litige de réconciliation (US-TERM-08, CA-8) : traitement par un agent, hors périmètre L3. */
enum StatutJournalReconciliation: string
{
    case Ouvert = 'ouvert';
    case Regularise = 'regularise';
    case Ignore = 'ignore';
}
