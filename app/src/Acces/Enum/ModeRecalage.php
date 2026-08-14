<?php

declare(strict_types=1);

namespace App\Acces\Enum;

/** Mode de recalage de la FMI à l'ouverture de site (§4.5, arbitrage point ouvert n°5). */
enum ModeRecalage: string
{
    case RemiseAZero = 'remise_a_zero';
    case ReportResiduel = 'report_residuel';
}
