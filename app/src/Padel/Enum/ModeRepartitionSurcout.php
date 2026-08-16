<?php

declare(strict_types=1);

namespace App\Padel\Enum;

/** Mode de répartition du surcoût « partie maintenue à 3 » (décision structurante n°4 du plan). */
enum ModeRepartitionSurcout: string
{
    case EquitablePresents = 'equitable_presents';
}
