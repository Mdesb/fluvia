<?php

declare(strict_types=1);

namespace App\Reporting\Enum;

/** Format d'un `Export`/`RapportPlanifie` (§4.6 spec). Seul `Csv` est réellement généré en MVP. */
enum FormatExport: string
{
    case Pdf = 'pdf';
    case Xlsx = 'xlsx';
    case Csv = 'csv';
}
