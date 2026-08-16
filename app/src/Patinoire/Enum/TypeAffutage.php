<?php

declare(strict_types=1);

namespace App\Patinoire\Enum;

/** Double casquette de l'affûtage (RG-PAT-06, décision actée « affûtage », §4.6). */
enum TypeAffutage: string
{
    case PrestationClient = 'prestation_client';
    case MaintenanceParc = 'maintenance_parc';
}
