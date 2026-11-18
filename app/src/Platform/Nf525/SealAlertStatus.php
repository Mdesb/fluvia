<?php

declare(strict_types=1);

namespace App\Platform\Nf525;

/** Cycle de vie d'une alerte NF525 : ouverte tant que la chaîne est bloquée, résolue ensuite. */
enum SealAlertStatus: string
{
    case Open = 'open';
    case Resolved = 'resolved';
}
