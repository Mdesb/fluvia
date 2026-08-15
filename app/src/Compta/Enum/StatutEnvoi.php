<?php

declare(strict_types=1);

namespace App\Compta\Enum;

enum StatutEnvoi: string
{
    case Prepare = 'prepare';
    case Transmis = 'transmis';
    case Rejete = 'rejete';
}
