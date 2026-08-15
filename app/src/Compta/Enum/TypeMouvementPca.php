<?php

declare(strict_types=1);

namespace App\Compta\Enum;

enum TypeMouvementPca: string
{
    case Dotation = 'dotation';
    case Reprise = 'reprise';
}
