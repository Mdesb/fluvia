<?php

declare(strict_types=1);

namespace App\Finance\ExpenseReport\Enum;

/**
 * Cycle de vie d'une `ExpenseReport` (RG-EXP-01/04/05/07, plan §1) — cinq valeurs, aucune sixième pour
 * « approuvée, en attente de déversement » (§0.5 du plan : `ledgerEntry === null` sur une note
 * `Approved` est le seul marqueur technique de cet état intermédiaire).
 */
enum ExpenseReportStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Reimbursed = 'reimbursed';
}
