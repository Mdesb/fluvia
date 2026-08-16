<?php

declare(strict_types=1);

namespace App\Reporting\Enum;

/** Module producteur d'un `Indicateur` (RG-M7-02) : pilote le dispatch vers la projection idoine. */
enum SourceModuleIndicateur: string
{
    case Vente = 'vente';
    case Acces = 'acces';
    case Compta = 'compta';
    case Reservation = 'reservation';
    case Recouvrement = 'recouvrement';
}
