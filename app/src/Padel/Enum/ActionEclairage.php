<?php

declare(strict_types=1);

namespace App\Padel\Enum;

/** Action de pilotage du relais d'éclairage (§4.9). */
enum ActionEclairage: string
{
    case Allumage = 'allumage';
    case Extinction = 'extinction';
}
