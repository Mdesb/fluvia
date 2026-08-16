<?php

declare(strict_types=1);

namespace App\Reporting\Enum;

/** Marquage de complétude d'une `Mesure` consolidée (RG-M7-08, RG-REPORT-11) — jamais silencieux. */
enum StatutCompletude: string
{
    case Complet = 'complet';
    case Partiel = 'partiel';
}
