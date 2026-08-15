<?php

declare(strict_types=1);

namespace App\Crm\Enum;

enum StatutFamille: string
{
    case Active = 'active';
    case Fusionnee = 'fusionnee';
    case Dissoute = 'dissoute';
}
