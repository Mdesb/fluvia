<?php

declare(strict_types=1);

namespace App\Reservation\Enum;

/** Résolution d'un conflit sur une occurrence récurrente (RG-M5-11). */
enum RegleConflitRecurrence: string
{
    case ReportAuto = 'report_auto';
    case ValidationManuelle = 'validation_manuelle';
}
