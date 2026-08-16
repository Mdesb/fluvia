<?php

declare(strict_types=1);

namespace App\Musee\Enum;

/** Cycle de vie d'un reversement OTA (RG-MUS-04). */
enum StatutReversement: string
{
    case AVerser = 'a_verser';
    case Verse = 'verse';
}
