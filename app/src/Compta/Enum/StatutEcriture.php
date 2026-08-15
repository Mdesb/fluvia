<?php

declare(strict_types=1);

namespace App\Compta\Enum;

/** Cycle de vie écriture (§4.10 spec) : Provisoire → Contrôlée/lettrée → Validée → Exportée. */
enum StatutEcriture: string
{
    case Provisoire = 'provisoire';
    case Controlee = 'controlee';
    case Validee = 'validee';
    case Exportee = 'exportee';
}
