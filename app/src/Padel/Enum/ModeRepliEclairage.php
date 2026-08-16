<?php

declare(strict_types=1);

namespace App\Padel\Enum;

/** Mode de repli en cas de défaut du relais d'éclairage (§4.9), seul mode livré dans ce lot. */
enum ModeRepliEclairage: string
{
    case Manuel = 'manuel';
}
