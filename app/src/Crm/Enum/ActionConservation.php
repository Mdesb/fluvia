<?php

declare(strict_types=1);

namespace App\Crm\Enum;

enum ActionConservation: string
{
    case Purge = 'purge';
    case Anonymisation = 'anonymisation';
}
