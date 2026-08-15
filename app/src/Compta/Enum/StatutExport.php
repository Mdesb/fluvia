<?php

declare(strict_types=1);

namespace App\Compta\Enum;

enum StatutExport: string
{
    case Genere = 'genere';
    case BloqueAnomalies = 'bloque_anomalies';
    case Transmis = 'transmis';
}
