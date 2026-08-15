<?php

declare(strict_types=1);

namespace App\Recouvrement\Enum;

/** Canal de résolution d'un impayé (moteur générique de recouvrement). */
enum CanalResolutionImpaye: string
{
    case App1Clic = 'app_1_clic';
    case Virement = 'virement';
    case Caisse = 'caisse';
    case Autre = 'autre';
}
