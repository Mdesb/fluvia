<?php

declare(strict_types=1);

namespace App\Reporting\Enum;

/** Traçabilité de génération/envoi d'un `Export` (§2.8 plan-reporting.md). */
enum StatutExport: string
{
    case Genere = 'genere';
    case Envoye = 'envoye';
    case Echec = 'echec';
}
