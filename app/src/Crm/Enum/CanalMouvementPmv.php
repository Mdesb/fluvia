<?php

declare(strict_types=1);

namespace App\Crm\Enum;

enum CanalMouvementPmv: string
{
    case Caisse = 'caisse';
    case EnLigne = 'en_ligne';
    case Autre = 'autre';
}
