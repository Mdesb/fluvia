<?php

declare(strict_types=1);

namespace App\Support\Enum;

/** Priorité déclarative d'un `TicketSupport` (RG-SUP-10) — n'engage aucun SLA/minuteur. */
enum PrioriteTicket: string
{
    case Basse = 'basse';
    case Normale = 'normale';
    case Haute = 'haute';
    case Critique = 'critique';
}
