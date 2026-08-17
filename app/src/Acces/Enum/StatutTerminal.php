<?php

declare(strict_types=1);

namespace App\Acces\Enum;

/**
 * Statut d'un `Terminal` (US-TERM-01/09, plan-acces-terminal.md §1.1). `EnAttente` est défini pour un
 * usage futur (provisioning en deux temps) mais n'est pas utilisé par l'enrôlement MVP : le CA-1 exige
 * qu'un `Terminal` soit `Actif` dès sa création (le premier `JetonTerminal` étant émis en synchrone).
 */
enum StatutTerminal: string
{
    case Actif = 'actif';
    case Revoque = 'revoque';
    case EnAttente = 'en_attente';
}
