<?php

declare(strict_types=1);

namespace App\Sport\Enum;

/** Canal de résolution d'un impayé (RG-SPORT-03). */
enum CanalResolutionImpaye: string
{
    case App1Clic = 'app_1_clic';
    case Virement = 'virement';
    case Caisse = 'caisse';
    case Autre = 'autre';
}
