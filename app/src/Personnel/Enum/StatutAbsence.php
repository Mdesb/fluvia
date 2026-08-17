<?php

declare(strict_types=1);

namespace App\Personnel\Enum;

/** Statut d'une Absence (RG-PERSO-05). */
enum StatutAbsence: string
{
    case Declaree = 'declaree';
    case Validee = 'validee';
    case Refusee = 'refusee';
}
