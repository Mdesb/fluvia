<?php

declare(strict_types=1);

namespace App\Boutique\Enum;

/** Statut d'un reversement à un partenaire OTA (RG-M3-09, §1.6 plan-boutique.md). */
enum StatutReversementOTA: string
{
    case AVerser = 'a_verser';
    case Verse = 'verse';
}
