<?php

declare(strict_types=1);

namespace App\Reporting\Enum;

/** Unité d'affichage d'un `Indicateur` (§5 spec). */
enum UniteIndicateur: string
{
    case Euro = 'euro';
    case Nombre = 'nombre';
    case Pourcentage = 'pourcentage';
    case Ratio = 'ratio';
}
