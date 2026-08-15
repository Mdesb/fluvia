<?php

declare(strict_types=1);

namespace App\Compta\Enum;

enum FaitGenerateurPca: string
{
    case Encaissement = 'encaissement';
    case Periode = 'periode';
    case Passage = 'passage';
}
