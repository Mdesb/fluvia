<?php

declare(strict_types=1);

namespace App\Compta\Enum;

enum SensCompte: string
{
    case Debit = 'debit';
    case Credit = 'credit';
}
